package kz.mtelecom.sales.data.local

import android.content.Context
import androidx.room.Dao
import androidx.room.Database
import androidx.room.Entity
import androidx.room.Insert
import androidx.room.OnConflictStrategy
import androidx.room.PrimaryKey
import androidx.room.Query
import androidx.room.Room
import androidx.room.RoomDatabase

@Entity(tableName = "outbox")
data class OutboxItem(
    @PrimaryKey val id: String,
    val kind: String,
    val payloadJson: String,
    val createdAt: Long,
    val attempts: Int = 0
)

@Dao
interface OutboxDao {
    @Query("SELECT * FROM outbox ORDER BY createdAt ASC")
    suspend fun all(): List<OutboxItem>

    @Query("SELECT COUNT(*) FROM outbox")
    suspend fun count(): Int

    @Insert(onConflict = OnConflictStrategy.IGNORE)
    suspend fun insert(item: OutboxItem)

    @Query("DELETE FROM outbox WHERE id = :id")
    suspend fun delete(id: String)

    @Query("UPDATE outbox SET attempts = attempts + 1 WHERE id = :id")
    suspend fun bump(id: String)
}

@Database(entities = [OutboxItem::class], version = 1, exportSchema = false)
abstract class SalesDatabase : RoomDatabase() {
    abstract fun outbox(): OutboxDao

    companion object {
        @Volatile private var instance: SalesDatabase? = null
        fun get(context: Context): SalesDatabase =
            instance ?: synchronized(this) {
                instance ?: Room.databaseBuilder(context, SalesDatabase::class.java, "mts-sales.db").build().also { instance = it }
            }
    }
}
