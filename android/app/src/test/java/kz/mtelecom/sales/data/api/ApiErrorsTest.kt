package kz.mtelecom.sales.data.api

import org.junit.Assert.assertFalse
import org.junit.Assert.assertTrue
import org.junit.Test
import java.io.IOException
import java.net.UnknownHostException

class ApiErrorsTest {
    @Test
    fun networkErrorsAreQueueable() {
        assertTrue(ApiErrors.isQueueable(UnknownHostException("offline")))
        assertTrue(ApiErrors.isQueueable(IOException("broken pipe")))
    }

    @Test
    fun authErrorsAreNotQueueable() {
        assertFalse(ApiErrors.isUnauthorized(IOException("x")))
    }
}
