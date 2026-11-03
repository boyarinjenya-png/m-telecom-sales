package kz.mtelecom.sales.data.sync

import android.content.Context
import androidx.work.BackoffPolicy
import androidx.work.Constraints
import androidx.work.CoroutineWorker
import androidx.work.ExistingWorkPolicy
import androidx.work.NetworkType
import androidx.work.OneTimeWorkRequestBuilder
import androidx.work.WorkManager
import androidx.work.WorkerParameters
import com.google.gson.Gson
import com.google.gson.JsonObject
import kz.mtelecom.sales.data.SessionStore
import kz.mtelecom.sales.data.api.ApiErrors
import kz.mtelecom.sales.data.api.ApiFactory
import kz.mtelecom.sales.data.api.SyncOp
import kz.mtelecom.sales.data.api.SyncPushRequest
import kz.mtelecom.sales.data.local.SalesDatabase
import java.util.concurrent.TimeUnit

class SyncWorker(context: Context, params: WorkerParameters) : CoroutineWorker(context, params) {
    override suspend fun doWork(): Result {
        val store = SessionStore(applicationContext)
        if (store.token.isNullOrBlank()) return Result.success()
        val dao = SalesDatabase.get(applicationContext).outbox()
        val items = dao.all()
        if (items.isEmpty()) return Result.success()
        val api = ApiFactory.create(store)
        val gson = Gson()
        return try {
            val ops = items.map {
                SyncOp(it.id, it.kind, gson.fromJson(it.payloadJson, JsonObject::class.java))
            }
            val response = api.syncPush(SyncPushRequest(ops))
            val byId = response.results.associateBy { it.clientOpId }
            var failed = 0
            items.forEach { item ->
                val row = byId[item.id]
                when {
                    row?.ok == true -> dao.delete(item.id)
                    ApplicationOutbox.isPermanentFailure(row?.status) -> {
                        // Drop poison pills (forbidden transition, validation) so they do not block the queue forever.
                        dao.delete(item.id)
                    }
                    else -> {
                        dao.bump(item.id)
                        failed++
                    }
                }
            }
            if (failed > 0) Result.retry() else Result.success()
        } catch (e: Exception) {
            if (ApiErrors.isUnauthorized(e)) {
                Result.failure()
            } else {
                Result.retry()
            }
        }
    }

    companion object {
        const val UNIQUE_ONE_SHOT = "mts-sync-now"

        fun enqueueNow(context: Context) {
            val request = OneTimeWorkRequestBuilder<SyncWorker>()
                .setConstraints(
                    Constraints.Builder()
                        .setRequiredNetworkType(NetworkType.CONNECTED)
                        .build()
                )
                .setBackoffCriteria(BackoffPolicy.EXPONENTIAL, 30, TimeUnit.SECONDS)
                .build()
            WorkManager.getInstance(context).enqueueUniqueWork(
                UNIQUE_ONE_SHOT,
                ExistingWorkPolicy.KEEP,
                request
            )
        }
    }
}
