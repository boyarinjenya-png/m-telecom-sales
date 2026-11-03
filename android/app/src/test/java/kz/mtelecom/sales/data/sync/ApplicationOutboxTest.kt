package kz.mtelecom.sales.data.sync

import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertTrue
import org.junit.Test

class ApplicationOutboxTest {
    @Test
    fun payloadContainsClientCommentAndIdempotency() {
        val p = ApplicationOutbox.payload(42, "Комментарий заявки", "key-1")
        assertEquals(42, p["client_id"])
        assertEquals("Комментарий заявки", p["comment"])
        assertEquals("key-1", p["idempotency_key"])
        assertEquals("application_received", p["status"])
        assertEquals("client.application", ApplicationOutbox.KIND)
    }

    @Test
    fun stableOutboxIdSurvivesAppRestartSemantics() {
        // Same idempotency key → same Room primary key → insert IGNORE on re-queue.
        assertEquals("app:abc-123", ApplicationOutbox.outboxIdFor("abc-123"))
        assertEquals(
            ApplicationOutbox.outboxIdFor("same"),
            ApplicationOutbox.outboxIdFor("same")
        )
    }

    @Test
    fun permanentFailuresAreNotRetried() {
        assertTrue(ApplicationOutbox.isPermanentFailure(400))
        assertTrue(ApplicationOutbox.isPermanentFailure(403))
        assertTrue(ApplicationOutbox.isPermanentFailure(404))
        assertFalse(ApplicationOutbox.isPermanentFailure(500))
        assertFalse(ApplicationOutbox.isPermanentFailure(503))
        assertFalse(ApplicationOutbox.isPermanentFailure(null))
    }
}
