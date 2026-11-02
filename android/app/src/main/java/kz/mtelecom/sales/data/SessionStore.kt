package kz.mtelecom.sales.data

import android.content.Context
import androidx.security.crypto.EncryptedSharedPreferences
import androidx.security.crypto.MasterKey
import kz.mtelecom.sales.BuildConfig

class SessionStore(context: Context) {
    private val master = MasterKey.Builder(context)
        .setKeyScheme(MasterKey.KeyScheme.AES256_GCM)
        .build()
    private val prefs = EncryptedSharedPreferences.create(
        context,
        "mts_secure_session",
        master,
        EncryptedSharedPreferences.PrefKeyEncryptionScheme.AES256_SIV,
        EncryptedSharedPreferences.PrefValueEncryptionScheme.AES256_GCM
    )

    var token: String?
        get() = prefs.getString("token", null)
        set(value) { prefs.edit().putString("token", value).apply() }

    var apiUrl: String
        get() = prefs.getString("api_url", BuildConfig.DEFAULT_API_URL) ?: BuildConfig.DEFAULT_API_URL
        set(value) { prefs.edit().putString("api_url", value.trim().trimEnd('/')).apply() }

    var userName: String?
        get() = prefs.getString("user_name", null)
        set(value) { prefs.edit().putString("user_name", value).apply() }

    var userRole: String?
        get() = prefs.getString("user_role", null)
        set(value) { prefs.edit().putString("user_role", value).apply() }

    fun clear() {
        val url = apiUrl
        prefs.edit().clear().apply()
        apiUrl = url
    }
}
