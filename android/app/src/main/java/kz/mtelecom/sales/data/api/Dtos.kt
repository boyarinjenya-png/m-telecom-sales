package kz.mtelecom.sales.data.api

import com.google.gson.JsonObject
import com.google.gson.annotations.SerializedName

data class ApiError(val error: String? = null, val ok: Boolean? = null)
data class LoginRequest(
    val login: String,
    val password: String,
    val platform: String = "android",
    val device: String? = null
)
data class LoginResponse(
    val ok: Boolean? = null,
    val token: String? = null,
    val user: UserDto? = null,
    val error: String? = null
)
data class UserDto(
    val id: Int,
    val login: String,
    val full_name: String,
    val role: String,
    val status: String,
    val phone: String? = null
)
data class MeResponse(val ok: Boolean? = null, val user: UserDto? = null)
data class DashboardResponse(val ok: Boolean? = null, val dashboard: Map<String, Any?>? = null)
data class ClientListResponse(val ok: Boolean? = null, val items: List<ClientDto> = emptyList(), val total: Int = 0)
data class ClientResponse(val ok: Boolean? = null, val client: ClientDto? = null, val error: String? = null, val duplicates: List<JsonObject>? = null)
data class ClientDto(
    val id: Int? = null,
    val uuid: String? = null,
    val type: String? = null,
    val type_label: String? = null,
    val status: String? = null,
    val status_label: String? = null,
    val display_name: String? = null,
    val full_name: String? = null,
    val company_name: String? = null,
    val phone_primary: String? = null,
    val phone_secondary: String? = null,
    val address: String? = null,
    val lat: Double? = null,
    val lng: Double? = null,
    val current_provider: String? = null,
    val need: String? = null,
    val comment: String? = null,
    val bin: String? = null,
    val legal_address: String? = null,
    val actual_address: String? = null,
    val potential_connections: Int? = null,
    val company_needs: String? = null,
    val manager_name: String? = null,
    val next_contact_at: String? = null,
    val updated_at: String? = null,
    val phones: List<JsonObject>? = null,
    val contacts: List<ContactDto>? = null,
    val objects: List<ClientObjectDto>? = null,
    val timeline: List<TimelineDto>? = null,
    val calls: List<CallDto>? = null,
    val whatsapp: List<WhatsappEventDto>? = null,
    val notes: List<TimelineDto>? = null,
    val meetings: List<TimelineDto>? = null,
    val tasks: List<TaskDto>? = null,
    val offers: List<TimelineDto>? = null,
    val applications: List<StatusEventDto>? = null,
    val connections: List<StatusEventDto>? = null,
    val status_history: List<StatusEventDto>? = null
)
data class ContactDto(
    val id: Int? = null,
    val full_name: String? = null,
    val position: String? = null,
    val phone: String? = null,
    val email: String? = null
)
data class CallDto(
    val id: Int? = null,
    val started_at: String? = null,
    val manager_name: String? = null,
    val phone: String? = null,
    val direction_label: String? = null,
    val channel_label: String? = null,
    val talk_duration_sec: Int? = null,
    val is_talk: Boolean? = null,
    val duration_is_actual: Boolean? = null,
    val data_source: String? = null,
    val category_label: String? = null,
    val result_label: String? = null,
    val source_label: String? = null,
    val data_source_label: String? = null,
    val comment: String? = null
)
data class WhatsappEventDto(
    val created_at: String? = null,
    val manager_name: String? = null,
    val phone: String? = null,
    val kind_label: String? = null,
    val offer_sent: Int? = null,
    val duration_sec: Int? = null,
    val data_source_label: String? = null,
    val comment: String? = null
)
data class StatusEventDto(
    val created_at: String? = null,
    val user_name: String? = null,
    val from_label: String? = null,
    val to_label: String? = null
)
data class ClientObjectDto(
    val id: Int? = null,
    val name: String? = null,
    val address: String? = null,
    val lat: Double? = null,
    val lng: Double? = null
)
data class TimelineDto(
    val id: Int? = null,
    val type: String? = null,
    val type_label: String? = null,
    val created_at: String? = null,
    val user_name: String? = null,
    val result_label: String? = null,
    val interest_label: String? = null,
    val topic: String? = null,
    val offered: String? = null,
    val comment: String? = null,
    val source_label: String? = null
)
data class TaskListResponse(val ok: Boolean? = null, val items: List<TaskDto> = emptyList())
data class TaskDto(
    val id: Int? = null,
    val title: String? = null,
    val details: String? = null,
    val due_at: String? = null,
    val status: String? = null,
    val client_display: String? = null,
    val overdue: Boolean? = null
)
data class VersionResponse(
    val version_code: Int? = null,
    val version_name: String? = null,
    val apk_url: String? = null,
    val changelog: String? = null
)
data class GenericOk(val ok: Boolean? = null, val error: String? = null)
data class CallResponse(val ok: Boolean? = null, val call: CallDto? = null, val error: String? = null)
data class CallBody(
    val phone: String,
    val connected: Boolean,
    val talk_duration_sec: Int,
    val duration_sec: Int,
    val result: String?,
    val interest: String?,
    val topic: String?,
    val offered: String?,
    val next_action: String?,
    val next_contact_at: String?,
    val comment: String?,
    val source: String,
    val idempotency_key: String,
    val direction: String = "outgoing",
    val channel: String = "sim",
    val data_source: String = "unverified",
    val attempt: Boolean = false
)
data class CallCompleteBody(
    val connected: Boolean? = null,
    val talk_duration_sec: Int? = null,
    val duration_sec: Int? = null,
    val result: String? = null,
    val interest: String? = null,
    val topic: String? = null,
    val offered: String? = null,
    val next_action: String? = null,
    val next_contact_at: String? = null,
    val comment: String? = null,
    val source: String? = null,
    val data_source: String? = null,
    val create_application: Boolean? = null
)
data class InteractionBody(
    val type: String,
    val comment: String? = null,
    val topic: String? = null,
    val offered: String? = null,
    val result: String? = null,
    val next_contact_at: String? = null,
    val idempotency_key: String
)
data class WhatsappBody(
    val phone: String,
    val kind: String,
    val offer_sent: Boolean,
    val result: String?,
    val interest: String?,
    val topic: String?,
    val offered: String?,
    val next_action: String?,
    val next_contact_at: String?,
    val comment: String?,
    val source: String = "manager",
    val idempotency_key: String,
    val duration_sec: Int? = null,
    val data_source: String = "manual"
)
data class ClientCreateBody(
    val type: String,
    val full_name: String? = null,
    val phone_primary: String? = null,
    val phone_secondary: String? = null,
    val address: String? = null,
    val lat: Double? = null,
    val lng: Double? = null,
    val current_provider: String? = null,
    val need: String? = null,
    val comment: String? = null,
    val company_name: String? = null,
    val bin: String? = null,
    val legal_address: String? = null,
    val actual_address: String? = null,
    val potential_connections: Int? = null,
    val company_needs: String? = null,
    val force: Boolean = false,
    val idempotency_key: String
)
data class SyncPushRequest(val ops: List<SyncOp>)
data class SyncOp(
    @SerializedName("client_op_id") val clientOpId: String,
    val kind: String,
    val payload: JsonObject
)
data class SyncPushResponse(
    val ok: Boolean? = null,
    val results: List<SyncOpResult> = emptyList()
)
data class SyncOpResult(
    @SerializedName("client_op_id") val clientOpId: String? = null,
    val ok: Boolean? = null,
    val error: String? = null,
    val status: Int? = null
)
data class CatalogResponse(
    val statuses: Map<String, String> = emptyMap(),
    val results: Map<String, String> = emptyMap(),
    val contact_results: Map<String, String> = emptyMap(),
    val next_workday: String? = null,
    val interest: Map<String, String> = emptyMap(),
    val whatsapp_kinds: Map<String, String> = emptyMap(),
    val manager_transitions: Map<String, List<String>> = emptyMap(),
    val admin_only_statuses: List<String> = emptyList()
)
