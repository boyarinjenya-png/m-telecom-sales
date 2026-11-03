package kz.mtelecom.sales.ui

import android.app.Application
import android.os.Build
import androidx.lifecycle.AndroidViewModel
import androidx.lifecycle.viewModelScope
import com.google.gson.Gson
import kz.mtelecom.sales.BuildConfig
import kz.mtelecom.sales.data.SessionStore
import kz.mtelecom.sales.data.api.ApiErrors
import kz.mtelecom.sales.data.api.ApiFactory
import kz.mtelecom.sales.data.api.ApiService
import kz.mtelecom.sales.data.api.CallBody
import kz.mtelecom.sales.data.api.CallCompleteBody
import kz.mtelecom.sales.data.api.ClientCreateBody
import kz.mtelecom.sales.data.api.InteractionBody
import kz.mtelecom.sales.data.api.ClientDto
import kz.mtelecom.sales.data.api.LoginRequest
import kz.mtelecom.sales.data.api.TaskDto
import kz.mtelecom.sales.data.api.VersionResponse
import kz.mtelecom.sales.data.api.WhatsappBody
import kz.mtelecom.sales.data.local.OutboxItem
import kz.mtelecom.sales.data.local.SalesDatabase
import kz.mtelecom.sales.data.sync.ApplicationOutbox
import kz.mtelecom.sales.data.sync.SyncWorker
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.launch
import java.util.UUID

data class PendingDial(
    val clientId: Int,
    val phone: String,
    val kind: String,
    val startedAt: Long = System.currentTimeMillis(),
    val callId: Int? = null
)

data class ResultPrompt(
    val clientId: Int,
    val phone: String,
    val kind: String,
    val durationSec: Int?,
    val connected: Boolean?,
    val source: String,
    val callId: Int? = null,
    val dataSource: String = "unverified"
)

data class UiState(
    val loggedIn: Boolean = false,
    val loading: Boolean = false,
    val error: String? = null,
    val userName: String? = null,
    val dashboard: Map<String, Any?> = emptyMap(),
    val dashboardPeriod: String = "today",
    val clients: List<ClientDto> = emptyList(),
    val client: ClientDto? = null,
    val tasks: List<TaskDto> = emptyList(),
    val pending: Int = 0,
    val version: VersionResponse? = null,
    val statuses: Map<String, String> = emptyMap(),
    val results: Map<String, String> = emptyMap(),
    val contactResults: Map<String, String> = emptyMap(),
    val nextWorkday: String? = null,
    val managerTransitions: Map<String, List<String>> = emptyMap(),
    val userRole: String? = null,
    val resultPrompt: ResultPrompt? = null
)

class AppViewModel(app: Application) : AndroidViewModel(app) {
    private val store = SessionStore(app)
    private val db = SalesDatabase.get(app)
    private val gson = Gson()
    private var pendingDial: PendingDial? = null
    private fun api(): ApiService = ApiFactory.create(store)

    private val _state = MutableStateFlow(
        UiState(
            loggedIn = !store.token.isNullOrBlank(),
            userName = store.userName,
            userRole = store.userRole
        )
    )
    val state = _state.asStateFlow()

    var apiUrl: String
        get() = store.apiUrl
        set(value) { store.apiUrl = value }

    init {
        if (_state.value.loggedIn) {
            refreshAll()
            checkVersion()
        }
    }

    fun login(login: String, password: String) {
        viewModelScope.launch {
            _state.value = _state.value.copy(loading = true, error = null)
            try {
                val res = api().login(LoginRequest(login, password, "android", Build.MODEL))
                if (res.token.isNullOrBlank() || res.user == null) {
                    _state.value = _state.value.copy(loading = false, error = res.error ?: "Неверный логин или пароль")
                    return@launch
                }
                if (res.user.role != "manager" && res.user.role != "admin") {
                    _state.value = _state.value.copy(loading = false, error = "Нет доступа")
                    return@launch
                }
                store.token = res.token
                store.userName = res.user.full_name
                store.userRole = res.user.role
                _state.value = _state.value.copy(
                    loggedIn = true,
                    loading = false,
                    userName = res.user.full_name,
                    userRole = res.user.role
                )
                refreshAll()
                checkVersion()
            } catch (e: Exception) {
                _state.value = _state.value.copy(loading = false, error = ApiErrors.message(e))
            }
        }
    }

    fun logout() {
        viewModelScope.launch {
            try { api().logout() } catch (_: Exception) {}
            store.clear()
            _state.value = UiState()
        }
    }

    fun setDashboardPeriod(period: String) {
        if (period == _state.value.dashboardPeriod) return
        _state.value = _state.value.copy(dashboardPeriod = period)
        refreshAll()
    }

    fun refreshAll() {
        viewModelScope.launch {
            _state.value = _state.value.copy(loading = true, error = null)
            try {
                val period = _state.value.dashboardPeriod
                val dash = api().dashboard(period).dashboard ?: emptyMap()
                val clients = api().clients().items
                val tasks = api().tasks().items
                val cat = api().catalog()
                val pending = db.outbox().count()
                _state.value = _state.value.copy(
                    loading = false,
                    dashboard = dash,
                    clients = clients,
                    tasks = tasks,
                    pending = pending,
                    statuses = cat.statuses,
                    results = cat.results,
                    contactResults = if (cat.contact_results.isNotEmpty()) cat.contact_results else cat.results,
                    nextWorkday = cat.next_workday,
                    managerTransitions = cat.manager_transitions,
                    userRole = store.userRole
                )
            } catch (e: Exception) {
                if (handleAuth(e)) return@launch
                val pending = try { db.outbox().count() } catch (_: Exception) { _state.value.pending }
                _state.value = _state.value.copy(
                    loading = false,
                    pending = pending,
                    error = if (ApiErrors.isOffline(e)) {
                        "Нет сети. Показаны последние загруженные данные, очередь сохранена."
                    } else {
                        ApiErrors.message(e)
                    }
                )
            }
        }
    }

    fun openClient(id: Int) {
        viewModelScope.launch {
            try {
                _state.value = _state.value.copy(client = api().client(id).client, error = null)
            } catch (e: Exception) {
                if (handleAuth(e)) return@launch
                _state.value = _state.value.copy(error = ApiErrors.message(e))
            }
        }
    }

    fun closeClient() {
        _state.value = _state.value.copy(client = null)
    }

    fun search(q: String) {
        viewModelScope.launch {
            try {
                _state.value = _state.value.copy(clients = api().clients(q = q).items)
            } catch (e: Exception) {
                if (!handleAuth(e)) {
                    _state.value = _state.value.copy(error = ApiErrors.message(e))
                }
            }
        }
    }

    fun createClient(body: ClientCreateBody, onDup: (String) -> Unit, onOk: () -> Unit) {
        viewModelScope.launch {
            try {
                val res = api().createClient(body)
                if (res.code() == 409) {
                    onDup("Найден возможный дубль. Подтвердите создание.")
                    return@launch
                }
                if (!res.isSuccessful) {
                    _state.value = _state.value.copy(error = "Не удалось создать клиента")
                    return@launch
                }
                refreshAll()
                onOk()
            } catch (e: Exception) {
                if (handleAuth(e)) return@launch
                enqueue("client.create", gson.toJson(body))
                _state.value = _state.value.copy(error = "Нет сети. Клиент сохранён в очередь синхронизации.")
                onOk()
            }
        }
    }

    fun changeStatus(id: Int, status: String) {
        viewModelScope.launch {
            try {
                api().changeStatus(id, mapOf("status" to status))
                openClient(id)
            } catch (e: Exception) {
                if (handleAuth(e)) return@launch
                enqueue("client.status", gson.toJson(mapOf("id" to id, "status" to status)))
                _state.value = _state.value.copy(error = ApiErrors.message(e))
            }
        }
    }

    fun startDial(clientId: Int, phone: String, onReady: () -> Unit) {
        viewModelScope.launch {
            var callId: Int? = null
            val body = CallBody(
                phone = phone,
                connected = false,
                talk_duration_sec = 0,
                duration_sec = 0,
                result = "no_answer",
                interest = "unknown",
                topic = null,
                offered = null,
                next_action = null,
                next_contact_at = null,
                comment = "Исходящий вызов инициирован",
                source = "manager",
                data_source = "unverified",
                attempt = true,
                idempotency_key = UUID.randomUUID().toString()
            )
            try {
                callId = api().recordCall(clientId, body).call?.id
            } catch (e: Exception) {
                if (handleAuth(e)) return@launch
            }
            pendingDial = PendingDial(clientId, phone, "call", System.currentTimeMillis(), callId)
            onReady()
        }
    }

    fun startWhatsapp(clientId: Int, phone: String, kind: String) {
        pendingDial = PendingDial(clientId, phone, kind, System.currentTimeMillis())
    }

    fun consumePendingDial(lookup: (phone: String, sinceMillis: Long) -> Pair<Int, Boolean>?) {
        val pending = pendingDial ?: return
        pendingDial = null
        val match = if (pending.kind == "call") lookup(pending.phone, pending.startedAt) else null
        val fromLog = match != null
        val duration = match?.first
        val connected = match?.second
        val system = pending.kind == "call" && fromLog
        _state.value = _state.value.copy(
            resultPrompt = ResultPrompt(
                clientId = pending.clientId,
                phone = pending.phone,
                kind = pending.kind,
                durationSec = if (system) duration else null,
                connected = if (system) connected else null,
                source = if (system) "system" else "manager",
                callId = pending.callId,
                dataSource = if (system) "system_verified" else if (pending.kind == "call") "unverified" else "manual"
            )
        )
    }

    fun clearResultPrompt() {
        _state.value = _state.value.copy(resultPrompt = null)
    }

    fun recordCall(clientId: Int, body: CallBody, done: () -> Unit) {
        viewModelScope.launch {
            try {
                api().recordCall(clientId, body)
            } catch (e: Exception) {
                if (handleAuth(e)) {
                    done()
                    return@launch
                }
                enqueue("call", gson.toJson(body.applyClient(clientId)))
            }
            openClient(clientId)
            refreshAll()
            done()
        }
    }

    fun completeCall(callId: Int, clientId: Int, body: CallCompleteBody, done: () -> Unit) {
        viewModelScope.launch {
            try {
                api().completeCall(callId, body)
            } catch (e: Exception) {
                if (handleAuth(e)) {
                    done()
                    return@launch
                }
                enqueue("call.complete", gson.toJson(body.applyId(callId, clientId)))
            }
            openClient(clientId)
            refreshAll()
            done()
        }
    }

    fun recordWhatsapp(clientId: Int, body: WhatsappBody, done: () -> Unit) {
        viewModelScope.launch {
            try {
                api().recordWhatsapp(clientId, body)
            } catch (e: Exception) {
                if (handleAuth(e)) {
                    done()
                    return@launch
                }
                enqueue("whatsapp", gson.toJson(body.applyClient(clientId)))
            }
            openClient(clientId)
            refreshAll()
            done()
        }
    }

    fun addInteraction(clientId: Int, body: InteractionBody, done: () -> Unit) {
        viewModelScope.launch {
            try {
                api().createInteraction(clientId, body)
            } catch (e: Exception) {
                if (handleAuth(e)) {
                    done()
                    return@launch
                }
                enqueue("interaction", gson.toJson(body.applyClient(clientId)))
                _state.value = _state.value.copy(error = ApiErrors.message(e))
            }
            openClient(clientId)
            refreshAll()
            done()
        }
    }

    fun addClientTask(clientId: Int, title: String, details: String, dueAt: String?, done: () -> Unit) {
        viewModelScope.launch {
            val key = UUID.randomUUID().toString()
            val payload = mapOf(
                "client_id" to clientId,
                "title" to title,
                "details" to details,
                "due_at" to dueAt,
                "idempotency_key" to key
            )
            try {
                api().createTask(payload)
            } catch (e: Exception) {
                if (handleAuth(e)) {
                    done()
                    return@launch
                }
                enqueue("task.create", gson.toJson(payload))
            }
            openClient(clientId)
            refreshAll()
            done()
        }
    }

    fun createApplication(clientId: Int, comment: String, done: () -> Unit) {
        viewModelScope.launch {
            val key = UUID.randomUUID().toString()
            val payload = ApplicationOutbox.payload(clientId, comment, key)
            try {
                api().createApplication(clientId, payload)
                openClient(clientId)
                refreshAll()
                done()
            } catch (e: Exception) {
                if (handleAuth(e)) {
                    done()
                    return@launch
                }
                if (ApiErrors.isQueueable(e)) {
                    // Persist across process death via Room; stable outbox id prevents double-queue on retries.
                    enqueue(
                        ApplicationOutbox.KIND,
                        gson.toJson(payload),
                        ApplicationOutbox.outboxIdFor(key)
                    )
                    _state.value = _state.value.copy(
                        error = "Нет сети. Заявка сохранена в очередь и отправится после восстановления связи."
                    )
                    done()
                } else {
                    _state.value = _state.value.copy(error = ApiErrors.message(e))
                    done()
                }
            }
        }
    }

    fun completeTask(id: Int) {
        viewModelScope.launch {
            try {
                api().updateTask(id, mapOf("status" to "done"))
                refreshAll()
            } catch (e: Exception) {
                if (handleAuth(e)) return@launch
                enqueue("task.update", gson.toJson(mapOf("id" to id, "status" to "done")))
                _state.value = _state.value.copy(error = ApiErrors.message(e))
            }
        }
    }

    fun syncNow() {
        SyncWorker.enqueueNow(getApplication())
        refreshAll()
    }

    fun checkVersion() {
        viewModelScope.launch {
            try {
                val v = api().version()
                if ((v.version_code ?: 0) > BuildConfig.VERSION_CODE) {
                    _state.value = _state.value.copy(version = v)
                }
            } catch (_: Exception) {}
        }
    }

    fun dismissUpdate() { _state.value = _state.value.copy(version = null) }

    private fun handleAuth(error: Throwable): Boolean {
        if (!ApiErrors.isUnauthorized(error)) return false
        store.clear()
        _state.value = UiState(error = "Сессия истекла. Войдите снова.")
        return true
    }

    private suspend fun enqueue(kind: String, payload: String, id: String = UUID.randomUUID().toString()) {
        db.outbox().insert(
            OutboxItem(id, kind, payload, System.currentTimeMillis())
        )
        _state.value = _state.value.copy(pending = db.outbox().count())
        SyncWorker.enqueueNow(getApplication())
    }
}

private fun CallBody.applyClient(id: Int): Map<String, Any?> = mapOf(
    "client_id" to id,
    "phone" to phone,
    "connected" to connected,
    "talk_duration_sec" to talk_duration_sec,
    "duration_sec" to duration_sec,
    "result" to result,
    "interest" to interest,
    "topic" to topic,
    "offered" to offered,
    "next_action" to next_action,
    "next_contact_at" to next_contact_at,
    "comment" to comment,
    "source" to source,
    "data_source" to data_source,
    "attempt" to attempt,
    "idempotency_key" to idempotency_key
)

private fun CallCompleteBody.applyId(callId: Int, clientId: Int): Map<String, Any?> = mapOf(
    "id" to callId,
    "call_id" to callId,
    "client_id" to clientId,
    "connected" to connected,
    "talk_duration_sec" to talk_duration_sec,
    "duration_sec" to duration_sec,
    "result" to result,
    "interest" to interest,
    "topic" to topic,
    "offered" to offered,
    "next_action" to next_action,
    "next_contact_at" to next_contact_at,
    "comment" to comment,
    "source" to source,
    "data_source" to data_source,
    "create_application" to create_application
)

private fun WhatsappBody.applyClient(id: Int): Map<String, Any?> = mapOf(
    "client_id" to id,
    "phone" to phone,
    "kind" to kind,
    "offer_sent" to offer_sent,
    "result" to result,
    "interest" to interest,
    "topic" to topic,
    "offered" to offered,
    "next_action" to next_action,
    "next_contact_at" to next_contact_at,
    "comment" to comment,
    "source" to source,
    "duration_sec" to duration_sec,
    "data_source" to data_source,
    "idempotency_key" to idempotency_key
)

private fun InteractionBody.applyClient(id: Int): Map<String, Any?> = mapOf(
    "client_id" to id,
    "type" to type,
    "comment" to comment,
    "topic" to topic,
    "offered" to offered,
    "result" to result,
    "next_contact_at" to next_contact_at,
    "idempotency_key" to idempotency_key
)
