// Auto-refresh scan status page
(function () {
  const el = document.querySelector("[data-auto-refresh]");
  if (!el) return;
  const seconds = parseInt(el.getAttribute("data-auto-refresh") || "3", 10);
  setTimeout(function () {
    window.location.reload();
  }, seconds * 1000);
})();

// Select all checkboxes for bulk verify
(function () {
  const master = document.getElementById("select-all");
  if (!master) return;
  master.addEventListener("change", function () {
    document.querySelectorAll('input[name="entity_ids"]').forEach(function (cb) {
      cb.checked = master.checked;
    });
  });
})();
