package kz.mtelecom.sales.data.api

import retrofit2.Response
import retrofit2.http.Body
import retrofit2.http.GET
import retrofit2.http.PATCH
import retrofit2.http.POST
import retrofit2.http.PUT
import retrofit2.http.Path
import retrofit2.http.Query

interface ApiService {
    @POST("api/auth/login")
    suspend fun login(@Body body: LoginRequest): LoginResponse

    @POST("api/auth/logout")
    suspend fun logout(): GenericOk

    @GET("api/auth/me")
    suspend fun me(): MeResponse

    @GET("api/dashboard")
    suspend fun dashboard(@Query("period") period: String = "today"): DashboardResponse

    @GET("api/clients")
    suspend fun clients(
        @Query("q") q: String? = null,
        @Query("status") status: String? = null,
        @Query("type") type: String? = null,
        @Query("per_page") perPage: Int = 100
    ): ClientListResponse

    @GET("api/clients/{id}")
    suspend fun client(@Path("id") id: Int): ClientResponse

    @POST("api/clients")
    suspend fun createClient(@Body body: ClientCreateBody): Response<ClientResponse>

    @PUT("api/clients/{id}")
    suspend fun updateClient(@Path("id") id: Int, @Body body: Map<String, @JvmSuppressWildcards Any?>): ClientResponse

    @POST("api/clients/{id}/status")
    suspend fun changeStatus(@Path("id") id: Int, @Body body: Map<String, String>): ClientResponse

    @POST("api/clients/{id}/calls")
    suspend fun recordCall(@Path("id") id: Int, @Body body: CallBody): CallResponse

    @PATCH("api/calls/{id}")
    suspend fun completeCall(@Path("id") id: Int, @Body body: CallCompleteBody): CallResponse

    @POST("api/clients/{id}/whatsapp")
    suspend fun recordWhatsapp(@Path("id") id: Int, @Body body: WhatsappBody): GenericOk

    @POST("api/clients/{id}/interactions")
    suspend fun createInteraction(@Path("id") id: Int, @Body body: InteractionBody): GenericOk

    @GET("api/tasks")
    suspend fun tasks(@Query("bucket") bucket: String? = null): TaskListResponse

    @POST("api/tasks")
    suspend fun createTask(@Body body: Map<String, @JvmSuppressWildcards Any?>): GenericOk

    @PATCH("api/tasks/{id}")
    suspend fun updateTask(@Path("id") id: Int, @Body body: Map<String, @JvmSuppressWildcards Any?>): GenericOk

    @GET("api/app/version")
    suspend fun version(): VersionResponse

    @GET("api/catalog")
    suspend fun catalog(): CatalogResponse

    @POST("api/sync/push")
    suspend fun syncPush(@Body body: SyncPushRequest): SyncPushResponse
}
