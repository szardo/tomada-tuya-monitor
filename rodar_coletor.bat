@echo off
REM Executa o coletor uma vez e grava o resultado no log.
REM No Windows, o Agendador de Tarefas chama este arquivo a cada minuto (veja o README).
cd /d "%~dp0"
"%~dp0venv\Scripts\python.exe" "%~dp0coletor_tomada.py" >> "%~dp0coletor_tomada.log" 2>&1
