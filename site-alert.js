/* =========================================================
   Site-wide accessible toast/alert component.
   Include this script (and css/site-alert.css) on any page
   and call showToast(message, type) instead of alert().
   type: "success" | "error" | "warning" | "info" (default: "success")
   ========================================================= */

(function () {
  function ensureContainer() {
    var container = document.getElementById("siteToastContainer");
    if (!container) {
      container = document.createElement("div");
      container.id = "siteToastContainer";
      document.body.appendChild(container);
    }
    return container;
  }

  window.showToast = function (message, type) {
    if (!message) return;
    type = type || "success";

    var container = ensureContainer();

    var toast = document.createElement("div");
    toast.className = "site-toast site-toast-" + type;
    toast.setAttribute("role", type === "error" ? "alert" : "status");
    toast.setAttribute("aria-live", type === "error" ? "assertive" : "polite");

    var text = document.createElement("span");
    text.textContent = message;
    toast.appendChild(text);

    var closeBtn = document.createElement("button");
    closeBtn.className = "site-toast-close";
    closeBtn.type = "button";
    closeBtn.setAttribute("aria-label", "Dismiss notification");
    closeBtn.innerHTML = "&times;";
    toast.appendChild(closeBtn);

    container.appendChild(toast);

    // Force reflow so the transition runs.
    requestAnimationFrame(function () {
      toast.classList.add("show");
    });

    var timer = setTimeout(function () {
      dismiss();
    }, 4000);

    function dismiss() {
      clearTimeout(timer);
      toast.classList.remove("show");
      setTimeout(function () {
        if (toast.parentNode) toast.parentNode.removeChild(toast);
      }, 250);
    }

    closeBtn.addEventListener("click", dismiss);
  };

  // Reusable confirm modal: showConfirm({title, message, confirmLabel, cancelLabel, danger})
  // Returns a Promise<boolean>.
  window.showConfirm = function (options) {
    options = options || {};
    return new Promise(function (resolve) {
      var overlay = document.createElement("div");
      overlay.className = "site-modal-overlay";

      var modal = document.createElement("div");
      modal.className = "site-modal";

      var title = document.createElement("h3");
      title.textContent = options.title || "Are you sure?";
      modal.appendChild(title);

      var msg = document.createElement("p");
      msg.textContent = options.message || "";
      modal.appendChild(msg);

      var actions = document.createElement("div");
      actions.className = "site-modal-actions";

      var cancelBtn = document.createElement("button");
      cancelBtn.type = "button";
      cancelBtn.className = "site-modal-btn site-modal-btn-cancel";
      cancelBtn.textContent = options.cancelLabel || "Cancel";

      var confirmBtn = document.createElement("button");
      confirmBtn.type = "button";
      confirmBtn.className =
        "site-modal-btn " + (options.danger ? "site-modal-btn-danger" : "site-modal-btn-primary");
      confirmBtn.textContent = options.confirmLabel || "Confirm";

      actions.appendChild(cancelBtn);
      actions.appendChild(confirmBtn);
      modal.appendChild(actions);
      overlay.appendChild(modal);
      document.body.appendChild(overlay);

      requestAnimationFrame(function () {
        overlay.classList.add("active");
      });

      function close(result) {
        overlay.classList.remove("active");
        setTimeout(function () {
          if (overlay.parentNode) overlay.parentNode.removeChild(overlay);
        }, 200);
        resolve(result);
      }

      cancelBtn.addEventListener("click", function () {
        close(false);
      });
      confirmBtn.addEventListener("click", function () {
        close(true);
      });
      overlay.addEventListener("click", function (e) {
        if (e.target === overlay) close(false);
      });
    });
  };
})();
