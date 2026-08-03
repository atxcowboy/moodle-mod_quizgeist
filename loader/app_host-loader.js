define("mod_quizgeist/app_host", [], function() {
  "use strict";

  var globalName = "QuizgeistHostApp";
  var bundleName = "app_host";
  var loadingPromise = null;

  function getBundle() {
    return window[globalName];
  }

  function loadBundle() {
    var existingBundle = getBundle();
    if (existingBundle && typeof existingBundle.init === "function") {
      return Promise.resolve(existingBundle);
    }
    if (loadingPromise) {
      return loadingPromise;
    }

    loadingPromise = new Promise(function(resolve, reject) {
      var script = document.createElement("script");
      var wwwroot = window.M && window.M.cfg && window.M.cfg.wwwroot
        ? window.M.cfg.wwwroot
        : "";

      script.src = wwwroot + "/mod/quizgeist/amd/build/" + bundleName + ".js";
      script.async = true;
      script.dataset.quizgeistBundle = bundleName;
      script.onload = function() {
        var bundle = getBundle();
        if (!bundle || typeof bundle.init !== "function") {
          loadingPromise = null;
          reject(new Error("Quizgeist-Bundle " + bundleName + " stellt init() nicht bereit."));
          return;
        }
        resolve(bundle);
      };
      script.onerror = function() {
        loadingPromise = null;
        reject(new Error("Quizgeist-Bundle " + bundleName + " konnte nicht geladen werden."));
      };
      document.head.appendChild(script);
    });

    return loadingPromise;
  }

  return {
    init: function(config) {
      config = config || {};
      return loadBundle().then(function(bundle) {
        bundle.init(config);
      }).catch(function(error) {
        window.console.error(error);
        throw error;
      });
    }
  };
});
