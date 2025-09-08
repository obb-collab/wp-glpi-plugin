(function () {
  'use strict';

  // Namespace for Chief page scripts
  const GLPIChief = {
    init() {
      const select = document.querySelector('.glpi-chief-assignee-select');
      if (select) {
        select.addEventListener('change', GLPIChief.handleChange);
      }
    },

    handleChange(event) {
      const value = event.target.value;
      // TODO: implement AJAX refresh of tickets list
      if (window.console && console.log) {
        console.log('GLPIChief assignee changed:', value);
      }
    }
  };

  document.addEventListener('DOMContentLoaded', GLPIChief.init);
})();
