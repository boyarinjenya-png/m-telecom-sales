@if "%DEBUG%"=="" @echo off
@rem Gradle wrapper launcher. Requires gradle-wrapper.jar next to this script
@rem under gradle\wrapper\. If the jar is missing, open the project in Android Studio.

set DIRNAME=%~dp0
if "%DIRNAME%"=="" set DIRNAME=.
set APP_HOME=%DIRNAME%

set JAVA_EXE=java.exe
if defined JAVA_HOME set JAVA_EXE=%JAVA_HOME%\bin\java.exe

set CLASSPATH=%APP_HOME%\gradle\wrapper\gradle-wrapper.jar
if not exist "%CLASSPATH%" (
  echo gradle-wrapper.jar not found. Open android/ in Android Studio to generate the wrapper.
  exit /b 1
)

"%JAVA_EXE%" -classpath "%CLASSPATH%" org.gradle.wrapper.GradleWrapperMain %*
