package kz.mtelecom.sales.ui.theme

import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.lightColorScheme
import androidx.compose.runtime.Composable
import androidx.compose.ui.graphics.Color

private val Navy = Color(0xFF0B3A5B)
private val Cyan = Color(0xFF00A3E0)
private val Bg = Color(0xFFF3F6F9)

@Composable
fun SalesTheme(content: @Composable () -> Unit) {
    MaterialTheme(
        colorScheme = lightColorScheme(
            primary = Navy,
            secondary = Cyan,
            background = Bg,
            surface = Color.White,
            onPrimary = Color.White,
            onBackground = Color(0xFF1A2330)
        ),
        content = content
    )
}
