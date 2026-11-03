package kz.mtelecom.sales

import android.Manifest
import android.content.Intent
import android.content.pm.PackageManager
import android.net.Uri
import android.os.Bundle
import androidx.core.content.ContextCompat
import androidx.activity.ComponentActivity
import androidx.activity.compose.setContent
import androidx.activity.result.contract.ActivityResultContracts
import androidx.activity.viewModels
import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.PaddingValues
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.grid.GridCells
import androidx.compose.foundation.lazy.grid.LazyVerticalGrid
import androidx.compose.foundation.lazy.grid.items
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.Add
import androidx.compose.material.icons.filled.Home
import androidx.compose.material.icons.filled.List
import androidx.compose.material.icons.filled.Person
import androidx.compose.material.icons.filled.Phone
import androidx.compose.material3.AlertDialog
import androidx.compose.material3.Button
import androidx.compose.material3.Card
import androidx.compose.material3.CardDefaults
import androidx.compose.material3.Checkbox
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.DropdownMenu
import androidx.compose.material3.DropdownMenuItem
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.ExposedDropdownMenuBox
import androidx.compose.material3.ExposedDropdownMenuDefaults
import androidx.compose.material3.FloatingActionButton
import androidx.compose.material3.Icon
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.NavigationBar
import androidx.compose.material3.NavigationBarItem
import androidx.compose.material3.OutlinedButton
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.Scaffold
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.material3.TopAppBar
import androidx.compose.material3.TopAppBarDefaults
import androidx.compose.runtime.Composable
import androidx.compose.runtime.collectAsState
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.input.PasswordVisualTransformation
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import kz.mtelecom.sales.call.PhoneActions
import kz.mtelecom.sales.data.api.CallBody
import kz.mtelecom.sales.data.api.CallCompleteBody
import kz.mtelecom.sales.data.api.ClientCreateBody
import kz.mtelecom.sales.data.api.ClientDto
import kz.mtelecom.sales.data.api.InteractionBody
import kz.mtelecom.sales.data.api.WhatsappBody
import kz.mtelecom.sales.ui.AppViewModel
import kz.mtelecom.sales.ui.ResultPrompt
import kz.mtelecom.sales.ui.theme.SalesTheme
import java.util.UUID

class MainActivity : ComponentActivity() {
    private val vm: AppViewModel by viewModels()
    private var afterPermissions: (() -> Unit)? = null
    private val permissionLauncher = registerForActivityResult(
        ActivityResultContracts.RequestMultiplePermissions()
    ) {
        afterPermissions?.invoke()
        afterPermissions = null
    }

    fun requestCallPermissionsThen(block: () -> Unit) {
        val needed = mutableListOf<String>()
        if (ContextCompat.checkSelfPermission(this, Manifest.permission.CALL_PHONE) != PackageManager.PERMISSION_GRANTED) {
            needed.add(Manifest.permission.CALL_PHONE)
        }
        if (ContextCompat.checkSelfPermission(this, Manifest.permission.READ_CALL_LOG) != PackageManager.PERMISSION_GRANTED) {
            needed.add(Manifest.permission.READ_CALL_LOG)
        }
        if (needed.isEmpty()) {
            block()
            return
        }
        afterPermissions = block
        permissionLauncher.launch(needed.toTypedArray())
    }

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        permissionLauncher.launch(arrayOf(
            Manifest.permission.CALL_PHONE,
            Manifest.permission.READ_CALL_LOG,
            Manifest.permission.POST_NOTIFICATIONS
        ))
        setContent {
            SalesTheme {
                val state by vm.state.collectAsState()
                if (!state.loggedIn) LoginPane(vm)
                else AppPane(vm, this)
                state.version?.let { v ->
                    AlertDialog(
                        onDismissRequest = { vm.dismissUpdate() },
                        title = { Text("Доступна версия ${v.version_name}") },
                        text = { Text(v.changelog ?: "Новое обновление M-TELECOM SALES") },
                        confirmButton = {
                            TextButton(onClick = {
                                v.apk_url?.takeIf { it.isNotBlank() }?.let {
                                    startActivity(Intent(Intent.ACTION_VIEW, Uri.parse(it)))
                                }
                                vm.dismissUpdate()
                            }) { Text("Скачать") }
                        },
                        dismissButton = { TextButton(onClick = { vm.dismissUpdate() }) { Text("Позже") } }
                    )
                }
            }
        }
    }

    override fun onResume() {
        super.onResume()
        vm.consumePendingDial { phone, since ->
            val match = PhoneActions.lastCallTo(this, phone, since)
            if (match == null) null else match.durationSec to match.connected
        }
    }
}

@Composable
private fun LoginPane(vm: AppViewModel) {
    val state by vm.state.collectAsState()
    var login by remember { mutableStateOf("") }
    var password by remember { mutableStateOf("") }
    var url by remember { mutableStateOf(vm.apiUrl) }
    Column(
        Modifier.fillMaxSize().background(Color(0xFF0B3A5B)).padding(24.dp),
        verticalArrangement = Arrangement.Center
    ) {
        Text("M-TELECOM SALES", color = Color.White, fontSize = 28.sp, fontWeight = FontWeight.Bold)
        Text("Кабинет менеджера", color = Color(0xFF9FD9F1), modifier = Modifier.padding(bottom = 24.dp))
        OutlinedTextField(
            url,
            { url = it; vm.apiUrl = it },
            label = { Text(if (kz.mtelecom.sales.BuildConfig.DEBUG) "Адрес тестового сервера" else "Адрес сервера") },
            modifier = Modifier.fillMaxWidth()
        )
        Spacer(Modifier.height(8.dp))
        OutlinedTextField(login, { login = it }, label = { Text("Логин") }, modifier = Modifier.fillMaxWidth())
        Spacer(Modifier.height(8.dp))
        OutlinedTextField(password, { password = it }, label = { Text("Пароль") }, visualTransformation = PasswordVisualTransformation(), modifier = Modifier.fillMaxWidth())
        state.error?.let { Text(it, color = Color(0xFFFFCDD2), modifier = Modifier.padding(top = 8.dp)) }
        Spacer(Modifier.height(16.dp))
        Button(onClick = { vm.login(login, password) }, modifier = Modifier.fillMaxWidth(), enabled = !state.loading) {
            Text(if (state.loading) "Вход…" else "Войти")
        }
    }
}

@OptIn(ExperimentalMaterial3Api::class)
@Composable
private fun AppPane(vm: AppViewModel, activity: MainActivity) {
    val state by vm.state.collectAsState()
    var tab by remember { mutableStateOf(0) }
    var showCreate by remember { mutableStateOf(false) }
    Scaffold(
        topBar = {
            TopAppBar(
                title = { Text(state.userName ?: "M-TELECOM SALES") },
                colors = TopAppBarDefaults.topAppBarColors(containerColor = Color(0xFF0B3A5B), titleContentColor = Color.White),
                actions = {
                    if (state.pending > 0) TextButton(onClick = { vm.syncNow() }) { Text("Очередь ${state.pending}", color = Color.White) }
                }
            )
        },
        bottomBar = {
            NavigationBar {
                NavigationBarItem(tab == 0, { tab = 0 }, icon = { Icon(Icons.Default.Home, null) }, label = { Text("Главная") })
                NavigationBarItem(tab == 1, { tab = 1; vm.state.value.client?.let { } }, icon = { Icon(Icons.Default.Person, null) }, label = { Text("Клиенты") })
                NavigationBarItem(tab == 2, { tab = 2 }, icon = { Icon(Icons.Default.List, null) }, label = { Text("Задачи") })
                NavigationBarItem(tab == 3, { tab = 3 }, icon = { Icon(Icons.Default.Phone, null) }, label = { Text("Ещё") })
            }
        },
        floatingActionButton = {
            if (tab == 1 && state.client == null) FloatingActionButton(onClick = { showCreate = true }) {
                Icon(Icons.Default.Add, contentDescription = "Создать")
            }
        }
    ) { pad ->
        when {
            showCreate -> CreateClientForm(pad, vm) { showCreate = false }
            tab == 0 -> HomeScreen(pad, vm)
            tab == 1 && state.client != null -> ClientCard(pad, vm, activity)
            tab == 1 -> ClientsList(pad, vm)
            tab == 2 -> TasksList(pad, vm)
            else -> MoreScreen(pad, vm)
        }
        if (state.loading && state.clients.isEmpty() && tab == 0) {
            CircularProgressIndicator(Modifier.padding(pad).padding(24.dp))
        }
    }
    state.resultPrompt?.let { prompt ->
        ResultDialog(
            prompt,
            vm,
            if (state.contactResults.isNotEmpty()) state.contactResults else state.results,
            state.nextWorkday
        ) { vm.clearResultPrompt() }
    }
}

@Composable
private fun HomeScreen(pad: PaddingValues, vm: AppViewModel) {
    val state by vm.state.collectAsState()
    val d = state.dashboard
    val period = state.dashboardPeriod
    val periodOptions = listOf(
        "today" to "Сегодня",
        "yesterday" to "Вчера",
        "last_7" to "7 дней",
        "last_30" to "30 дней",
        "this_month" to "Текущий месяц",
        "last_month" to "Предыдущий месяц"
    )
    // KPI set aligned with web admin «Обзор» (mtelecom.kz/sales).
    val cards = listOf(
        "Клиенты сейчас" to num(d["clients_total"]),
        "Новые за период" to num(d["clients_new"]),
        "Звонки за период" to num(d["calls_total"]),
        "Подтверждённые SIM" to num(d["sim_verified_total"]),
        "Неподтверждённые SIM" to num(d["sim_unverified"]),
        "Попытки дозвона" to num(d["sim_verified_attempts"] ?: d["dial_attempts"]),
        "Соединения" to num(d["sim_verified_connected"]),
        "Разговоры" to num(d["talks"]),
        "Подтверждённое время" to fmtSec(d["sim_verified_talk_seconds"] ?: d["talk_seconds"]),
        "WhatsApp за период" to num(d["whatsapp_total"]),
        "Стали заинтересованными" to num(d["interested"]),
        "КП за период" to num(d["offers"]),
        "Встречи за период" to num(d["meetings"]),
        "Заявки за период" to num(d["applications"]),
        "Подключения за период" to num(d["connections"]),
        "Отказы за период" to num(d["rejected"]),
        "Просроченные задачи сейчас" to num(d["overdue_tasks"]),
        "Контакты на сегодня" to num(d["today_contacts"])
    )
    Column(Modifier.fillMaxSize().padding(pad).padding(horizontal = 12.dp)) {
        var periodMenu by remember { mutableStateOf(false) }
        Box(Modifier.padding(top = 8.dp, bottom = 4.dp)) {
            OutlinedButton(onClick = { periodMenu = true }) {
                Text("Период: ${periodOptions.firstOrNull { it.first == period }?.second ?: period}")
            }
            DropdownMenu(expanded = periodMenu, onDismissRequest = { periodMenu = false }) {
                periodOptions.forEach { (id, label) ->
                    DropdownMenuItem(
                        text = { Text(label) },
                        onClick = {
                            periodMenu = false
                            vm.setDashboardPeriod(id)
                        }
                    )
                }
            }
        }
        Text(
            "События за выбранный период и отдельно текущий снимок базы.",
            fontSize = 12.sp,
            color = Color.Gray,
            modifier = Modifier.padding(bottom = 8.dp)
        )
        LazyVerticalGrid(
            columns = GridCells.Fixed(2),
            modifier = Modifier.weight(1f).fillMaxWidth(),
            verticalArrangement = Arrangement.spacedBy(8.dp),
            horizontalArrangement = Arrangement.spacedBy(8.dp)
        ) {
            items(cards) { (l, n) ->
                Card(colors = CardDefaults.cardColors(containerColor = Color.White)) {
                    Column(Modifier.padding(14.dp)) {
                        Text(n, fontSize = 22.sp, fontWeight = FontWeight.Bold, color = Color(0xFF0B3A5B))
                        Text(l, fontSize = 12.sp, color = Color.Gray)
                    }
                }
            }
        }
    }
}

@Composable
private fun ClientsList(pad: PaddingValues, vm: AppViewModel) {
    val state by vm.state.collectAsState()
    var q by remember { mutableStateOf("") }
    Column(Modifier.padding(pad).padding(12.dp)) {
        OutlinedTextField(q, { q = it; vm.search(it) }, label = { Text("Поиск") }, modifier = Modifier.fillMaxWidth())
        LazyColumn {
            items(state.clients, key = { it.id ?: 0 }) { c ->
                Card(Modifier.fillMaxWidth().padding(vertical = 6.dp).clickable { vm.openClient(c.id ?: return@clickable) }) {
                    Column(Modifier.padding(14.dp)) {
                        Text(c.display_name ?: "", fontWeight = FontWeight.SemiBold)
                        Text(listOfNotNull(c.phone_primary, c.status_label).joinToString(" · "), color = Color.Gray, fontSize = 13.sp)
                    }
                }
            }
        }
    }
}

@Composable
private fun ClientCard(pad: PaddingValues, vm: AppViewModel, activity: MainActivity) {
    val c = vm.state.collectAsState().value.client ?: return
    val phone = c.phone_primary ?: ""
    val id = c.id ?: return
    var form by remember { mutableStateOf<String?>(null) }
    var text1 by remember { mutableStateOf("") }
    var text2 by remember { mutableStateOf("") }
    var more by remember { mutableStateOf(false) }
    Column(Modifier.padding(pad).padding(16.dp).verticalScroll(rememberScrollState())) {
        TextButton(onClick = { vm.closeClient() }) { Text("← К списку") }
        Text(c.display_name ?: "", fontSize = 22.sp, fontWeight = FontWeight.Bold)
        Text("${c.type_label ?: ""} · ${c.status_label ?: ""} · ${c.manager_name ?: ""}", color = Color.Gray)
        Text(phone)
        if (!c.phone_secondary.isNullOrBlank()) Text("Доп. ${c.phone_secondary}")
        Text(c.address ?: c.actual_address ?: c.legal_address ?: "")
        if (!c.current_provider.isNullOrBlank()) Text("Провайдер: ${c.current_provider}")
        if (!c.need.isNullOrBlank() || !c.company_needs.isNullOrBlank()) Text(c.need ?: c.company_needs ?: "")
        if (c.type == "company") {
            if (!c.bin.isNullOrBlank()) Text("БИН: ${c.bin}")
            if (c.potential_connections != null) Text("Потенциальных подключений: ${c.potential_connections}")
        }
        Spacer(Modifier.height(8.dp))
        Row(horizontalArrangement = Arrangement.spacedBy(8.dp), modifier = Modifier.fillMaxWidth()) {
            Button(onClick = {
                activity.requestCallPermissionsThen {
                    vm.startDial(id, phone) {
                        PhoneActions.dial(activity, phone)
                    }
                }
            }, modifier = Modifier.weight(1f)) { Text("Позвонить") }
            OutlinedButton(onClick = {
                vm.startWhatsapp(id, phone, "whatsapp")
                PhoneActions.whatsappChat(activity, phone)
            }, modifier = Modifier.weight(1f)) { Text("WhatsApp") }
            Box {
                OutlinedButton(onClick = { more = true }) { Text("Ещё") }
                DropdownMenu(expanded = more, onDismissRequest = { more = false }) {
                    DropdownMenuItem(text = { Text("Звонок WhatsApp") }, onClick = {
                        more = false
                        vm.startWhatsapp(id, phone, "wa_call")
                        PhoneActions.whatsappCall(activity, phone)
                    })
                    DropdownMenuItem(text = { Text("Комментарий") }, onClick = { more = false; form = "note"; text1 = "" })
                    DropdownMenuItem(text = { Text("Встреча") }, onClick = { more = false; form = "meeting"; text1 = "" })
                    DropdownMenuItem(text = { Text("Задача") }, onClick = { more = false; form = "task"; text1 = ""; text2 = "" })
                    DropdownMenuItem(text = { Text("Коммерческое предложение") }, onClick = { more = false; form = "offer"; text1 = ""; text2 = "" })
                    DropdownMenuItem(text = { Text("Создать заявку") }, onClick = { more = false; form = "application"; text1 = "" })
                }
            }
        }
        StatusPicker(c, vm)
        SectionTitle("Контакты")
        (c.contacts ?: emptyList()).forEach { p ->
            Text("${p.full_name ?: ""} · ${p.position ?: ""} · ${p.phone ?: ""}", fontSize = 13.sp)
        }
        (c.objects ?: emptyList()).forEach { o ->
            Text("${o.name ?: ""} · ${o.address ?: ""}", fontSize = 13.sp)
        }
        SectionTitle("Звонки")
        if ((c.calls ?: emptyList()).isEmpty()) Text("Звонков нет", color = Color.Gray, fontSize = 13.sp)
        (c.calls ?: emptyList()).forEach { i ->
            Text(
                listOfNotNull(i.started_at, i.direction_label, i.channel_label, i.category_label, i.result_label, i.data_source_label ?: i.source_label).joinToString(" · "),
                fontSize = 13.sp
            )
            Text(
                when {
                    i.duration_is_actual == true && i.is_talk == true -> "Разговор ${i.talk_duration_sec} сек"
                    i.duration_is_actual == true -> "Попытка дозвона / короткое соединение"
                    else -> "Не подтверждено системой"
                },
                fontSize = 12.sp,
                color = Color.Gray
            )
        }
        SectionTitle("WhatsApp")
        if ((c.whatsapp ?: emptyList()).isEmpty()) Text("Событий WhatsApp нет", color = Color.Gray, fontSize = 13.sp)
        (c.whatsapp ?: emptyList()).forEach { i ->
            Text(
                listOfNotNull(
                    i.created_at,
                    i.kind_label,
                    if ((i.duration_sec ?: 0) > 0) "${i.duration_sec} сек · вручную" else null,
                    i.data_source_label,
                    i.comment
                ).joinToString(" · "),
                fontSize = 13.sp
            )
        }
        SectionTitle("Комментарии и встречи")
        (c.notes ?: emptyList()).forEach { t -> Text("${t.created_at} · ${t.comment ?: t.topic ?: ""}", fontSize = 13.sp) }
        (c.meetings ?: emptyList()).forEach { t -> Text("Встреча ${t.created_at} · ${t.topic ?: t.comment ?: ""}", fontSize = 13.sp) }
        SectionTitle("Задачи")
        (c.tasks ?: emptyList()).forEach { t ->
            Text("${t.title} · ${t.due_at ?: ""}", fontSize = 13.sp)
            if (t.overdue == true) Text("Просрочено", color = Color(0xFFC53030), fontSize = 12.sp)
        }
        SectionTitle("Коммерческие предложения")
        (c.offers ?: emptyList()).forEach { t -> Text("${t.created_at} · ${t.offered ?: t.comment ?: ""}", fontSize = 13.sp) }
        SectionTitle("Заявки")
        (c.applications ?: emptyList()).forEach { t -> Text("${t.created_at} · ${t.from_label ?: "—"} → ${t.to_label}", fontSize = 13.sp) }
        SectionTitle("Подключения")
        (c.connections ?: emptyList()).forEach { t -> Text("${t.created_at} · ${t.from_label ?: "—"} → ${t.to_label}", fontSize = 13.sp) }
        SectionTitle("История действий")
        (c.timeline ?: emptyList()).forEach { t ->
            Column(Modifier.padding(vertical = 8.dp)) {
                Text("${t.type_label} · ${t.created_at}", fontWeight = FontWeight.SemiBold)
                Text(listOfNotNull(t.user_name, t.result_label, t.interest_label).joinToString(" · "), fontSize = 13.sp)
                Text(t.topic ?: t.offered ?: t.comment ?: "", fontSize = 13.sp)
                Text(t.source_label ?: "", color = Color(0xFF0077A8), fontSize = 12.sp)
            }
        }
    }
    if (form != null) {
        AlertDialog(
            onDismissRequest = { form = null },
            title = {
                Text(
                    when (form) {
                        "task" -> "Задача"
                        "offer" -> "Коммерческое предложение"
                        "application" -> "Заявка"
                        "meeting" -> "Встреча"
                        else -> "Комментарий"
                    }
                )
            },
            text = {
                Column {
                    if (form == "task") {
                        OutlinedTextField(text1, { text1 = it }, label = { Text("Название") }, modifier = Modifier.fillMaxWidth())
                        OutlinedTextField(text2, { text2 = it }, label = { Text("Детали") }, modifier = Modifier.fillMaxWidth())
                    } else if (form == "offer") {
                        OutlinedTextField(text1, { text1 = it }, label = { Text("Что предложили") }, modifier = Modifier.fillMaxWidth())
                        OutlinedTextField(text2, { text2 = it }, label = { Text("Комментарий") }, modifier = Modifier.fillMaxWidth())
                    } else {
                        OutlinedTextField(text1, { text1 = it }, label = { Text("Текст") }, modifier = Modifier.fillMaxWidth())
                    }
                }
            },
            confirmButton = {
                TextButton(onClick = {
                    val key = UUID.randomUUID().toString()
                    when (form) {
                        "note" -> vm.addInteraction(id, InteractionBody("note", comment = text1, idempotency_key = key)) { form = null }
                        "meeting" -> vm.addInteraction(id, InteractionBody("meeting", comment = text1, result = "meeting_scheduled", idempotency_key = key)) { form = null }
                        "offer" -> vm.addInteraction(id, InteractionBody("offer", offered = text1, comment = text2, result = "offer_sent", idempotency_key = key)) { form = null }
                        "task" -> vm.addClientTask(id, text1, text2, null) { form = null }
                        "application" -> vm.createApplication(id, text1) { form = null }
                        else -> form = null
                    }
                }) { Text("Сохранить") }
            },
            dismissButton = { TextButton(onClick = { form = null }) { Text("Отмена") } }
        )
    }
}

@Composable
private fun SectionTitle(text: String) {
    Spacer(Modifier.height(12.dp))
    Text(text, fontWeight = FontWeight.Bold, color = Color(0xFF0B3A5B))
}

@OptIn(ExperimentalMaterial3Api::class)
@Composable
private fun StatusPicker(c: ClientDto, vm: AppViewModel) {
    val ui = vm.state.collectAsState().value
    val statuses = ui.statuses
    val current = c.status ?: "new"
    val allowedKeys = if (ui.userRole == "admin") {
        statuses.keys
    } else {
        (listOf(current) + (ui.managerTransitions[current] ?: emptyList())).distinct()
    }
    var expanded by remember { mutableStateOf(false) }
    // Always mirror server status for this client; do not keep stale optimistic value.
    val selected = current
    ExposedDropdownMenuBox(expanded, { expanded = !expanded }) {
        OutlinedTextField(
            value = statuses[selected] ?: selected,
            onValueChange = {},
            readOnly = true,
            label = { Text("Статус") },
            trailingIcon = { ExposedDropdownMenuDefaults.TrailingIcon(expanded) },
            modifier = Modifier.menuAnchor().fillMaxWidth()
        )
        ExposedDropdownMenu(expanded, { expanded = false }) {
            allowedKeys.forEach { k ->
                val v = statuses[k] ?: k
                DropdownMenuItem(text = { Text(v) }, onClick = {
                    expanded = false
                    if (k != current) {
                        vm.changeStatus(c.id ?: return@DropdownMenuItem, k)
                    }
                })
            }
        }
    }
}

@Composable
private fun CreateClientForm(pad: PaddingValues, vm: AppViewModel, onDone: () -> Unit) {
    var person by remember { mutableStateOf(true) }
    var name by remember { mutableStateOf("") }
    var phone by remember { mutableStateOf("") }
    var address by remember { mutableStateOf("") }
    var need by remember { mutableStateOf("") }
    var company by remember { mutableStateOf("") }
    var bin by remember { mutableStateOf("") }
    var warn by remember { mutableStateOf<String?>(null) }
    Column(Modifier.padding(pad).padding(16.dp).verticalScroll(rememberScrollState())) {
        Text("Новый клиент", fontSize = 20.sp, fontWeight = FontWeight.Bold)
        Row {
            TextButton(onClick = { person = true }) { Text(if (person) "• Физлицо" else "Физлицо") }
            TextButton(onClick = { person = false }) { Text(if (!person) "• Юрлицо" else "Юрлицо") }
        }
        if (person) OutlinedTextField(name, { name = it }, label = { Text("ФИО") }, modifier = Modifier.fillMaxWidth())
        else {
            OutlinedTextField(company, { company = it }, label = { Text("Название компании") }, modifier = Modifier.fillMaxWidth())
            OutlinedTextField(bin, { bin = it }, label = { Text("БИН") }, modifier = Modifier.fillMaxWidth())
        }
        OutlinedTextField(phone, { phone = it }, label = { Text("Телефон") }, modifier = Modifier.fillMaxWidth())
        OutlinedTextField(address, { address = it }, label = { Text("Адрес") }, modifier = Modifier.fillMaxWidth())
        OutlinedTextField(need, { need = it }, label = { Text("Потребность") }, modifier = Modifier.fillMaxWidth())
        warn?.let { Text(it, color = Color(0xFFC53030), modifier = Modifier.padding(top = 8.dp)) }
        Row(Modifier.padding(top = 12.dp), horizontalArrangement = Arrangement.spacedBy(8.dp)) {
            Button(onClick = {
                val body = ClientCreateBody(
                    type = if (person) "person" else "company",
                    full_name = name.ifBlank { null },
                    phone_primary = phone,
                    address = address,
                    need = need,
                    company_name = company.ifBlank { null },
                    bin = bin.ifBlank { null },
                    force = warn != null,
                    idempotency_key = UUID.randomUUID().toString()
                )
                vm.createClient(body, { warn = it }, onDone)
            }) { Text(if (warn != null) "Создать всё равно" else "Сохранить") }
            OutlinedButton(onClick = onDone) { Text("Отмена") }
        }
    }
}

@Composable
private fun TasksList(pad: PaddingValues, vm: AppViewModel) {
    val state by vm.state.collectAsState()
    LazyColumn(Modifier.padding(pad).padding(12.dp)) {
        items(state.tasks, key = { it.id ?: 0 }) { t ->
            Card(Modifier.fillMaxWidth().padding(vertical = 6.dp)) {
                Column(Modifier.padding(14.dp)) {
                    Text(t.title ?: "", fontWeight = FontWeight.SemiBold)
                    Text(listOfNotNull(t.client_display, t.due_at).joinToString(" · "), color = Color.Gray, fontSize = 13.sp)
                    if (t.overdue == true) Text("Просрочено", color = Color(0xFFC53030))
                    if (t.status == "open") TextButton(onClick = { vm.completeTask(t.id ?: return@TextButton) }) { Text("Выполнено") }
                }
            }
        }
    }
}

@Composable
private fun MoreScreen(pad: PaddingValues, vm: AppViewModel) {
    Column(Modifier.padding(pad).padding(16.dp)) {
        Text("Очередь без сети: ${vm.state.collectAsState().value.pending}")
        Button(onClick = { vm.syncNow() }, modifier = Modifier.padding(vertical = 8.dp)) { Text("Синхронизировать") }
        Button(onClick = { vm.refreshAll() }) { Text("Обновить данные") }
        Spacer(Modifier.height(24.dp))
        OutlinedButton(onClick = { vm.logout() }) { Text("Выйти") }
    }
}

@OptIn(ExperimentalMaterial3Api::class)
@Composable
private fun ResultDialog(
    prompt: ResultPrompt,
    vm: AppViewModel,
    results: Map<String, String>,
    nextWorkday: String?,
    onClose: () -> Unit
) {
    var result by remember { mutableStateOf(if (prompt.connected == true) "answered" else "no_answer") }
    var comment by remember { mutableStateOf("") }
    var waDuration by remember { mutableStateOf("") }
    var waKind by remember { mutableStateOf(if (prompt.kind == "wa_call") "call" else "message_out") }
    var next by remember { mutableStateOf((nextWorkday ?: "").replace("T", " ").take(16)) }
    var createApp by remember { mutableStateOf(false) }
    val system = prompt.dataSource == "system_verified" || prompt.source == "system"
    val compact = results.filterKeys {
        it in setOf(
            "no_answer", "answered", "interested", "not_interested", "callback",
            "awaiting_offer", "meeting_scheduled", "application_ready", "rejected"
        )
    }.ifEmpty { results }
    val needsNext = result in setOf("no_answer", "callback", "interested", "awaiting_offer", "meeting_scheduled")
    AlertDialog(
        onDismissRequest = onClose,
        title = { Text("Результат контакта") },
        text = {
            Column(Modifier.verticalScroll(rememberScrollState())) {
                if (prompt.kind == "call") {
                    Text(
                        if (system && prompt.durationSec != null) "Длительность: ${prompt.durationSec} сек. Нельзя изменить."
                        else "Не подтверждено системой. Длительность не записывается как фактическая."
                    )
                } else {
                    ResultMenu(
                        "Что произошло",
                        mapOf(
                            "message_out" to "Отправил сообщение",
                            "message_in" to "Клиент ответил",
                            "call" to "Звонок WhatsApp",
                            "chat" to "Открыл чат"
                        ),
                        waKind
                    ) { waKind = it }
                    if (waKind == "call") {
                        OutlinedTextField(
                            waDuration,
                            { waDuration = it.filter { ch -> ch.isDigit() } },
                            label = { Text("Длительность, сек (вручную)") },
                            modifier = Modifier.fillMaxWidth()
                        )
                    }
                }
                ResultMenu("Результат", compact, result) { result = it }
                if (needsNext) {
                    OutlinedTextField(
                        next,
                        { next = it },
                        label = { Text("Следующий контакт YYYY-MM-DD HH:MM") },
                        modifier = Modifier.fillMaxWidth()
                    )
                    Text("Можно изменить предложенную дату.", fontSize = 12.sp, color = Color.Gray)
                }
                if (result == "application_ready") {
                    Row(verticalAlignment = androidx.compose.ui.Alignment.CenterVertically) {
                        Checkbox(createApp, { createApp = it })
                        Text("Создать заявку")
                    }
                }
                OutlinedTextField(
                    comment,
                    { comment = it },
                    label = { Text(if (result == "rejected") "Причина отказа / комментарий" else "Короткий комментарий") },
                    modifier = Modifier.fillMaxWidth()
                )
            }
        },
        confirmButton = {
            TextButton(onClick = {
                val key = UUID.randomUUID().toString()
                val nextAt = next.takeIf { needsNext && it.length >= 16 }
                if (prompt.kind == "call") {
                    val talk = if (system) prompt.durationSec ?: 0 else 0
                    val complete = CallCompleteBody(
                        connected = if (system) prompt.connected ?: false else false,
                        talk_duration_sec = talk,
                        duration_sec = talk,
                        result = result,
                        next_contact_at = nextAt,
                        comment = comment,
                        source = if (system) "system" else "manager",
                        data_source = if (system) "system_verified" else "unverified",
                        create_application = createApp
                    )
                    if (prompt.callId != null) {
                        vm.completeCall(prompt.callId, prompt.clientId, complete, onClose)
                    } else {
                        vm.recordCall(
                            prompt.clientId,
                            CallBody(
                                phone = prompt.phone,
                                connected = complete.connected ?: false,
                                talk_duration_sec = talk,
                                duration_sec = talk,
                                result = result,
                                interest = null,
                                topic = null,
                                offered = null,
                                next_action = null,
                                next_contact_at = nextAt,
                                comment = comment,
                                source = complete.source ?: "manager",
                                data_source = complete.data_source ?: "unverified",
                                idempotency_key = key
                            ),
                            onClose
                        )
                    }
                } else {
                    vm.recordWhatsapp(
                        prompt.clientId,
                        WhatsappBody(
                            phone = prompt.phone,
                            kind = waKind,
                            offer_sent = result == "offer_sent" || waKind == "offer",
                            result = result,
                            interest = null,
                            topic = null,
                            offered = null,
                            next_action = null,
                            next_contact_at = nextAt,
                            comment = comment,
                            duration_sec = waDuration.toIntOrNull()?.takeIf { it > 0 },
                            data_source = "manual",
                            idempotency_key = key
                        ),
                        onClose
                    )
                }
            }) { Text("Сохранить") }
        },
        dismissButton = { TextButton(onClick = onClose) { Text("Закрыть") } }
    )
}

@OptIn(ExperimentalMaterial3Api::class)
@Composable
private fun ResultMenu(label: String, options: Map<String, String>, value: String, onPick: (String) -> Unit) {
    var expanded by remember { mutableStateOf(false) }
    ExposedDropdownMenuBox(expanded, { expanded = !expanded }) {
        OutlinedTextField(
            value = options[value] ?: value,
            onValueChange = {},
            readOnly = true,
            label = { Text(label) },
            modifier = Modifier.menuAnchor().fillMaxWidth()
        )
        ExposedDropdownMenu(expanded, { expanded = false }) {
            options.forEach { (k, v) ->
                DropdownMenuItem(text = { Text(v) }, onClick = { onPick(k); expanded = false })
            }
        }
    }
}

private fun num(v: Any?): String = when (v) {
    is Number -> v.toInt().toString()
    else -> v?.toString()?.substringBefore('.') ?: "0"
}

private fun fmtSec(v: Any?): String {
    val s = when (v) { is Number -> v.toInt(); else -> v?.toString()?.toDoubleOrNull()?.toInt() ?: 0 }
    val h = s / 3600
    val m = (s % 3600) / 60
    val sec = s % 60
    return when {
        h > 0 -> "${h}ч ${m}м"
        m > 0 -> "${m}м ${sec}с"
        else -> "${sec}с"
    }
}
