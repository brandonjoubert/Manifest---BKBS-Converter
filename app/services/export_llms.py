"""Shim — Stage 4b adapters live in app.exports.llms_txt."""

from app.exports.llms_txt import render_llms_full, render_llms_txt

__all__ = ["render_llms_txt", "render_llms_full"]
