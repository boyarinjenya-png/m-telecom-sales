package kz.mtelecom.sales.data.api

import kz.mtelecom.sales.BuildConfig
import kz.mtelecom.sales.data.SessionStore
import okhttp3.Interceptor
import okhttp3.OkHttpClient
import okhttp3.logging.HttpLoggingInterceptor
import retrofit2.Retrofit
import retrofit2.converter.gson.GsonConverterFactory
import java.util.concurrent.TimeUnit

object ApiFactory {
    fun create(store: SessionStore): ApiService {
        val auth = Interceptor { chain ->
            val builder = chain.request().newBuilder()
            store.token?.let { builder.header("Authorization", "Bearer $it") }
            builder.header("Accept", "application/json")
            chain.proceed(builder.build())
        }
        val logging = HttpLoggingInterceptor().apply {
            level = if (BuildConfig.DEBUG) {
                HttpLoggingInterceptor.Level.BASIC
            } else {
                HttpLoggingInterceptor.Level.NONE
            }
        }
        val client = OkHttpClient.Builder()
            .addInterceptor(auth)
            .addInterceptor(logging)
            .connectTimeout(20, TimeUnit.SECONDS)
            .readTimeout(30, TimeUnit.SECONDS)
            .build()
        val raw = store.apiUrl.ifBlank { BuildConfig.DEFAULT_API_URL }
        val base = if (raw.endsWith("/")) raw else "$raw/"
        return Retrofit.Builder()
            .baseUrl(base)
            .client(client)
            .addConverterFactory(GsonConverterFactory.create())
            .build()
            .create(ApiService::class.java)
    }
}
