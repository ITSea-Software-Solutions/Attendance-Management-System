@echo off
title AMS - Stopping Services
echo  Stopping Docker containers...
docker compose down
echo  All services stopped.
pause
