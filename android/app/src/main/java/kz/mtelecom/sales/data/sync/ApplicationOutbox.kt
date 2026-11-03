package kz.mtelecom.sales.data.sync

/**
 * Pure helpers for offline application (заявка) outbox payloads.
 * Kept free of Android framework types so JVM unit tests can cover the contract.
 */
object ApplicationOutbox {
    const val KIND = "client.application"

    fun payload(clientId: Int, comment: String, idempotencyKey: String): Map<String, Any?> = mapOf(
        "client_id" to clientId,
        "comment" to comment,
        "idempotency_key" to idempotencyKey,
        "status" to "application_received"
    )

    fun outboxIdFor(idempotencyKey: String): String = "app:$idempotencyKey"

    /** Server errors that must not be retried endlessly from the outbox. */
    fun isPermanentFailure(status: Int?): Boolean = when (status) {
        null -> false
        400, 403, 404, 409, 422 -> true
        else -> false
    }
}
