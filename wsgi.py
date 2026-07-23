"""Generic WSGI/ASGI bridge for hosts that expect wsgi.py."""

from app.main import app

# Uvicorn/Gunicorn ASGI
application = app
