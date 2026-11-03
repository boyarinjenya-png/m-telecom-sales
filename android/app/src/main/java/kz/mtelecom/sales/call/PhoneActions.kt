package kz.mtelecom.sales.call

import android.Manifest
import android.content.ActivityNotFoundException
import android.content.Context
import android.content.Intent
import android.content.pm.PackageManager
import android.net.Uri
import android.provider.CallLog
import androidx.core.content.ContextCompat

object PhoneActions {
    // READ_CALL_LOG is a restricted Play permission. This app is internal:
    // we read only the outgoing call that was just started from a client card,
    // never personal call history, never audio, never WhatsApp chats.
    fun hasReadCallLog(context: Context): Boolean =
        ContextCompat.checkSelfPermission(context, Manifest.permission.READ_CALL_LOG) == PackageManager.PERMISSION_GRANTED

    fun normalize(phone: String): String = PhoneNormalize.normalize(phone)

    fun dial(context: Context, phone: String) {
        val uri = Uri.parse("tel:+${normalize(phone)}")
        val callIntent = Intent(Intent.ACTION_CALL, uri)
        val dialIntent = Intent(Intent.ACTION_DIAL, uri)
        if (ContextCompat.checkSelfPermission(context, Manifest.permission.CALL_PHONE) == PackageManager.PERMISSION_GRANTED) {
            context.startActivity(callIntent.addFlags(Intent.FLAG_ACTIVITY_NEW_TASK))
        } else {
            context.startActivity(dialIntent.addFlags(Intent.FLAG_ACTIVITY_NEW_TASK))
        }
    }

    fun whatsappInstalled(context: Context): Boolean =
        isPackageInstalled(context, "com.whatsapp") || isPackageInstalled(context, "com.whatsapp.w4b")

    fun whatsappChat(context: Context, phone: String) {
        val url = PhoneNormalize.waMeUrl(phone)
        val view = Intent(Intent.ACTION_VIEW, Uri.parse(url)).addFlags(Intent.FLAG_ACTIVITY_NEW_TASK)
        // Prefer the installed WhatsApp / Business package so the system does not open a browser first.
        when {
            isPackageInstalled(context, "com.whatsapp") -> view.setPackage("com.whatsapp")
            isPackageInstalled(context, "com.whatsapp.w4b") -> view.setPackage("com.whatsapp.w4b")
        }
        try {
            context.startActivity(view)
        } catch (_: ActivityNotFoundException) {
            openPlayStoreOrBrowser(context, url)
        } catch (_: Exception) {
            openPlayStoreOrBrowser(context, url)
        }
    }

    fun whatsappCall(context: Context, phone: String) {
        val callUri = Uri.parse(PhoneNormalize.waCallUri(phone))
        val packages = buildList {
            if (isPackageInstalled(context, "com.whatsapp")) add("com.whatsapp")
            if (isPackageInstalled(context, "com.whatsapp.w4b")) add("com.whatsapp.w4b")
        }
        for (pkg in packages) {
            try {
                context.startActivity(
                    Intent(Intent.ACTION_VIEW, callUri)
                        .setPackage(pkg)
                        .addFlags(Intent.FLAG_ACTIVITY_NEW_TASK)
                )
                return
            } catch (_: Exception) {
                // try next package / fall through to chat
            }
        }
        // No WhatsApp call handler — open chat (or Play Store / browser if WhatsApp is absent).
        whatsappChat(context, phone)
    }

    private fun isPackageInstalled(context: Context, packageName: String): Boolean {
        return try {
            context.packageManager.getPackageInfo(packageName, 0)
            true
        } catch (_: PackageManager.NameNotFoundException) {
            false
        }
    }

    private fun openPlayStoreOrBrowser(context: Context, fallbackUrl: String) {
        val market = Intent(
            Intent.ACTION_VIEW,
            Uri.parse("market://details?id=com.whatsapp")
        ).addFlags(Intent.FLAG_ACTIVITY_NEW_TASK)
        try {
            context.startActivity(market)
        } catch (_: Exception) {
            context.startActivity(
                Intent(Intent.ACTION_VIEW, Uri.parse(fallbackUrl)).addFlags(Intent.FLAG_ACTIVITY_NEW_TASK)
            )
        }
    }

    data class CallLogMatch(val durationSec: Int, val connected: Boolean)

    fun lastCallTo(context: Context, phone: String, sinceMillis: Long? = null): CallLogMatch? {
        if (!hasReadCallLog(context)) {
            return null
        }
        if (phone.isBlank()) return null
        val want = normalize(phone)
        val cursor = context.contentResolver.query(
            CallLog.Calls.CONTENT_URI,
            arrayOf(CallLog.Calls.NUMBER, CallLog.Calls.DURATION, CallLog.Calls.TYPE, CallLog.Calls.DATE),
            "${CallLog.Calls.TYPE} = ?",
            arrayOf(CallLog.Calls.OUTGOING_TYPE.toString()),
            CallLog.Calls.DATE + " DESC"
        ) ?: return null
        cursor.use {
            val now = System.currentTimeMillis()
            val floor = (sinceMillis ?: (now - 15 * 60 * 1000)) - 5_000
            while (it.moveToNext()) {
                val number = normalize(it.getString(0) ?: "")
                val date = it.getLong(3)
                if (date < floor) continue
                if (now - date > 15 * 60 * 1000) break
                if (number.endsWith(want.takeLast(10)) || want.endsWith(number.takeLast(10))) {
                    val duration = it.getInt(1)
                    return CallLogMatch(duration, duration > 0)
                }
            }
        }
        return null
    }
}
