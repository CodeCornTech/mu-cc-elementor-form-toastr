<?php
/**
 * Plugin Name:  MU - CC Elementor Form Toastr (guarded)
 * Description:  Converte i messaggi dei form Elementor in toast Toastr, con guard-check che importa i CDN solo se mancanti.
 * Author:       CodeCorn™ Technology
 * Version:      1.0.46
 * License:      MIT
 */

if (!defined('ABSPATH'))
  exit;

final class MU_CC_Elementor_Form_Toastr
{
  public const VERSION = '1.0.46';
  public const BRAND_COLOR = '#c1a269';  // Oro CAG
  public const BRAND_COLOR2 = '#b69b6a';  // Oro soft
  public const ERROR_COLOR = '#a54e4e';
  public const INFO_COLOR = '#5b5b5b';
  public const POSITION = 'toast-top-right'; // posizione default

  public static function init()
  {
    // solo frontend (incluso anteprima Elementor), niente admin classico
    if (is_admin() && empty($_GET['elementor-preview']))
      return;
    add_action('wp_enqueue_scripts', [__CLASS__, 'enqueue_guard_and_boot'], 30);
  }

  public static function enqueue_guard_and_boot()
  {
    // 1) Handle vuoto per inline
    wp_register_script('cc-toastr-guard', false, [], self::VERSION, true);
    wp_enqueue_script('cc-toastr-guard');

    // 2) Brand CSS hardening (no pattern, z-index alto) background-color:transparent!important; /* niente overlay/pattern dal tema */
    $brand_css = sprintf(
      '/* CC Toastr brand skin (CAG) */
#toast-container>*{background-repeat:no-repeat!important;background-position: 15px center !important}
#toast-container>div{z-index:999999!important;background-image:none!important;opacity:0.95!important}
.toast{
  background-image:none!important;
  background-repeat:no-repeat!important;
  backdrop-filter:none!important;
  box-shadow:0 4px 16px rgba(0,0,0,0.25)!important;
}
.toast-success,.toast-error,.toast-warning,.toast-info{
  background-image:none!important;background-repeat:no-repeat!important
}

/* Palette brand */
.toast-success{background:%1$s!important;color:#1b1b1b!important;font-weight:600}
.toast-error{background:%2$s!important}
.toast-warning{background:%3$s!important;color:#1b1b1b!important}
.toast-info{background:%4$s!important}

/* Typography & details */
.toast-message{line-height:1.35}
.toast-close-button{filter:none}
',
      self::BRAND_COLOR,   // %1$s
      self::ERROR_COLOR,   // %2$s
      self::BRAND_COLOR2,  // %3$s
      self::INFO_COLOR     // %4$s
    );
    wp_register_style('cc-toastr-brand', false, [], self::VERSION);
    wp_enqueue_style('cc-toastr-brand');
    wp_add_inline_style('cc-toastr-brand', $brand_css);

    // 3) Guard-check + CDN generic + watchdog + helpers
    $inline_js = sprintf(
      '(function(){
  if (window.CC_TOASTR_BOOTSTRAPPED) return;
  window.CC_TOASTR_BOOTSTRAPPED = true;

  var CDN_JS  = "https://cdn.jsdelivr.net/npm/toastr@2.1.4/build/toastr.min.js";
  var CDN_CSS = "https://cdn.jsdelivr.net/npm/toastr@2.1.4/build/toastr.min.css";
  var POSITION = %s;

  function hasToastrCSS(){
    return !!document.querySelector(' . "\"link[href*=\\\"toastr\\\"][href$=\\\".css\\\"]\"" . ');
  }
  function hasToastrJS(){
    return !!document.querySelector(' . "\"script[src*=\\\"toastr\\\"][src$=\\\".js\\\"]\"" . ');
  }

  function ensureToastr(cb){
    // già pronto
    if (window.toastr && typeof window.toastr.success === "function") { cb && cb(); return; }

    // CSS: non duplicare se presente (qualsiasi CDN)
    if (!hasToastrCSS()) {
      var l = document.createElement("link");
      l.rel = "stylesheet";
      l.href = CDN_CSS;
      l.setAttribute("data-loaded-by","CCToastr");
      document.head.appendChild(l);
    }

    // JS: non duplicare se presente (qualsiasi CDN) oppure se già caricato da altri
    if (hasToastrJS() && window.toastr) { cb && cb(); return; }

    if (!hasToastrJS()) {
      var s = document.createElement("script");
      s.src   = CDN_JS;
      s.async = true;
      s.setAttribute("data-loaded-by","CCToastr");
      s.onload = function(){ cb && cb(); };
      document.head.appendChild(s);
    } else {
      // se esiste lo script ma toastr non è ancora pronto, poll leggero
      var tries = 0, t = setInterval(function(){
        if (window.toastr || tries++ > 60) { clearInterval(t); cb && cb(); }
      }, 50);
    }
  }

  function hardenToastrOptions(){
    if (!window.toastr) return;
    var wanted = {
      closeButton: true,
      progressBar: true,
      timeOut: 4500,
      extendedTimeOut: 2000,
      positionClass: POSITION || "toast-bottom-right",
      preventDuplicates: true,
      showMethod: "fadeIn",
      hideMethod: "fadeOut"
    };
    // imposta subito
    window.toastr.options = Object.assign({}, window.toastr.options || {}, wanted);

    // per 3s ri-applica se un altro script (es. EuroPlate) resetta
    var t0 = Date.now();
    var iv = setInterval(function(){
      if (Date.now() - t0 > 3000) return clearInterval(iv);
      var o = window.toastr.options || {};
      if (o.timeOut !== wanted.timeOut || o.extendedTimeOut !== wanted.extendedTimeOut || o.positionClass !== wanted.positionClass) {
        window.toastr.options = Object.assign({}, o, wanted);
      }
    }, 150);
  }

  // Helper pubblico: toast con override puntuale
  window.ccToast = function(type, message, title, override){
    if (!window.toastr) return;
    var fn = (toastr[type] || toastr.info).bind(toastr);
    return fn(message || "", title || "", Object.assign({
      positionClass: POSITION || "toast-bottom-right",
      preventDuplicates: true
    }, override || {}));
  };
  // Helper 50s persistente
  window.ccToast50 = function(message, title, type){
    type = type || "info";
    return window.ccToast(type, message || "⏱️ Notifica persistente 50s", title || "Debug Toastr", {
      timeOut: 50000,
      extendedTimeOut: 0,
      tapToDismiss: false,
      closeButton: true,
      progressBar: true
    });
  };

  function bootBridge(){
    if (!window.toastr) return;

    hardenToastrOptions();

    // Evita doppi bridge
    if (window.CC_TOASTR_BRIDGE_READY) return;
    window.CC_TOASTR_BRIDGE_READY = true;

    // 1) Intercetta i messaggi nativi Elementor
    var obs = new MutationObserver(function(){
      document.querySelectorAll(".elementor-message").forEach(function(msg){
        if (msg.dataset.ccToastShown) return;
        msg.dataset.ccToastShown = "1";

        var text = (msg.textContent || "").trim();
        if (!text) { msg.remove(); return; }

        if (msg.classList.contains("elementor-message-success")) {
          ccToast("success", text, "✅ Fatto!");
        } else if (msg.classList.contains("elementor-message-error")) {
          ccToast("error", text, "❌ Errore!");
        } else if (msg.classList.contains("elementor-message-warning")) {
          ccToast("warning", text, "⚠️ Attenzione");
        } else {
          ccToast("info", text, "ℹ️ Info");
        }
        msg.remove();
      });
    });
    obs.observe(document.body, {childList:true, subtree:true});

    // 2) Event hook extra Elementor
    document.addEventListener("elementor/form_submitted", function(e){
      try {
        var ok = !!(e && e.detail && e.detail.status === "success");
        if (ok) ccToast("success", "Inviato correttamente.", "✅ Fatto!");
      } catch(_){}
    }, {passive:true});

    // 3) Salvagente post-submit
    document.addEventListener("submit", function(ev){
      var form = ev.target;
      if (!form || !form.classList) return;
      if (form.classList.contains("elementor-form")) {
        setTimeout(function(){
          document.querySelectorAll(".elementor-message").forEach(function(x){ x.dataset.ccToastShown = ""; });
        }, 500);
      }
    }, true);
  }

  // DOM pronto → assicura toastr e avvia il bridge
  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", function(){ ensureToastr(bootBridge); });
  } else {
    ensureToastr(bootBridge);
  }
})();',
      wp_json_encode(self::POSITION)
    );

    wp_add_inline_script('cc-toastr-guard', $inline_js, 'after');
  }
}

MU_CC_Elementor_Form_Toastr::init();
