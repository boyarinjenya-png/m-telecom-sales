package kz.mtelecom.sales.call

import android.Manifest
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

    fun normalize(phone: String): String = phone.filter { it.isDigit() }.let {
        when {
            it.length == 11 && (it.startsWith("8") || it.startsWith("7")) -> "7" + it.substring(1)
            it.length == 10 -> "7$it"
            else -> it
        }
    }

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

    fun whatsappChat(context: Context, phone: String) {
        val url = "https://wa.me/${normalize(phone)}"
        context.startActivity(Intent(Intent.ACTION_VIEW, Uri.parse(url)).addFlags(Intent.FLAG_ACTIVITY_NEW_TASK))
    }

    fun whatsappCall(context: Context, phone: String) {
        val url = "https://wa.me/${normalize(phone)}"
        context.startActivity(Intent(Intent.ACTION_VIEW, Uri.parse(url)).addFlags(Intent.FLAG_ACTIVITY_NEW_TASK))
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
