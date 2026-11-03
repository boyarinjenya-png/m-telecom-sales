package kz.mtelecom.sales.call

import org.junit.Assert.assertEquals
import org.junit.Test

class PhoneNormalizeTest {
    @Test
    fun normalizesKazakhstanFormats() {
        val expected = "77001234567"
        assertEquals(expected, PhoneNormalize.normalize("87001234567"))
        assertEquals(expected, PhoneNormalize.normalize("+77001234567"))
        assertEquals(expected, PhoneNormalize.normalize("77001234567"))
        assertEquals(expected, PhoneNormalize.normalize("8 700 123 45 67"))
        assertEquals(expected, PhoneNormalize.normalize("7001234567"))
    }

    @Test
    fun buildsWhatsAppUrls() {
        assertEquals("https://wa.me/77001234567", PhoneNormalize.waMeUrl("+7 700 123 45 67"))
        assertEquals(
            "whatsapp://call?jids=77001234567@s.whatsapp.net",
            PhoneNormalize.waCallUri("87001234567")
        )
    }
}
