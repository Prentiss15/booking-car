@echo off
chcp 65001 > nul
title ระบบจองรถเดินทาง (Vehicle Booking System)
echo ======================================================
echo       กำลังเริ่มระบบจองรถ (Vehicle Booking System)
echo ======================================================
echo.

set "LOCAL_IP=10.191.2.22"
for /f "usebackq tokens=*" %%i in (`powershell -NoProfile -Command "(Get-NetIPConfiguration | Where-Object IPv4DefaultGateway).IPv4Address.IPAddress"`) do (
    set "LOCAL_IP=%%i"
)

echo ======================================================
echo 1. สำหรับใช้งานบนคอมพิวเตอร์เครื่องนี้:
echo    เปิดเบราว์เซอร์ไปที่: http://localhost:8000
echo.
echo 2. สำหรับพระภิกษุและเจ้าหน้าที่ใช้งานผ่านมือถือ (ต่อ Wi-Fi / วง LAN เดียวกัน):
echo    พิมพ์ในเบราว์เซอร์มือถือ: http://%LOCAL_IP%:8000
echo ======================================================
echo.
start "" "http://localhost:8000"
echo กด Ctrl+C เมื่อต้องการหยุดการทำงาน
echo ------------------------------------------------------
php -S 0.0.0.0:8000
pause
