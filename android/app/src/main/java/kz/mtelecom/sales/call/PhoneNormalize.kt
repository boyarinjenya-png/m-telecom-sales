package kz.mtelecom.sales.call

/**
 * Kazakhstan phone normalization shared with the PHP Catalog::normalizePhone rules.
 * Pure JVM-safe helper so unit tests do not need an Android runtime.
 */
object PhoneNormalize {
    fun normalize(phone: String): String = phone.filter { it.isDigit() }.let {
        when {
            it.length == 11 && (it.startsWith("8") || it.startsWith("7")) -> "7" + it.substring(1)
            it.length == 10 -> "7$it"
            else -> it
        }
    }

    fun waMeUrl(phone: String): String = "https://wa.me/${normalize(phone)}"

    fun waCallUri(phone: String): String =
        "whatsapp://call?jids=${normalize(phone)}@s.whatsapp.net"
}
