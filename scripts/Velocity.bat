@echo off
rem Velocity: starts the database and servers, opens the app in its own window,
rem and stops everything again when that window is closed.
rem Keep a copy of this file anywhere (the Desktop is fine); the work is done by velocity.ps1.
start "" /min powershell.exe -NoProfile -ExecutionPolicy Bypass -WindowStyle Hidden -File "C:\Projects\Velocity-v2\scripts\velocity.ps1"
