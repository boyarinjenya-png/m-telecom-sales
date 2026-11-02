package kz.mtelecom.sales.data.api

import com.google.gson.JsonParser
import retrofit2.HttpException
import java.io.IOException
import java.net.ConnectException
import java.net.SocketTimeoutException
import java.net.UnknownHostException

object ApiErrors {
    fun message(error: Throwable): String {
        if (error is HttpException) {
            parseBody(error)?.let { return it }
            return when (error.code()) {
                401 -> "Сессия истекла. Войдите снова."
                403 -> "Недостаточно прав"
                409 -> "Конфликт данных"
                419 -> "Сессия устарела"
                429 -> "Слишком много попыток. Повторите позже."
                in 500..599 -> "Сервер временно недоступен"
                else -> "Ошибка сервера (${error.code()})"
            }
        }
        return when (error) {
            is UnknownHostException, is ConnectException -> "Нет связи с сервером"
            is SocketTimeoutException -> "Сервер не ответил вовремя"
            is IOException -> "Нет сети"
            else -> error.message ?: "Неизвестная ошибка"
        }
    }

    fun isUnauthorized(error: Throwable): Boolean =
        error is HttpException && error.code() == 401

    fun isOffline(error: Throwable): Boolean =
        error is IOException && error !is HttpException

    private fun parseBody(error: HttpException): String? {
        val raw = try {
            error.response()?.errorBody()?.string()
        } catch (_: Exception) {
            null
        } ?: return null
        return try {
            val json = JsonParser.parseString(raw).asJsonObject
            json.get("error")?.asString?.takeIf { it.isNotBlank() }
        } catch (_: Exception) {
            null
        }
    }
}
