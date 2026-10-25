(() => {
  const form = document.getElementById('deviceForm');
  window.prepareDeviceSections = editing => {
    document.getElementById('deviceSection-identity').open = true;
    document.getElementById('deviceSection-connections').open = false;
    document.getElementById('deviceSection-maintenance').open = editing;
  };
  // Reveal invalid fields before the browser attempts to focus them.
  form.addEventListener('invalid', event => {
    const section = event.target.closest('details');
    if (section) section.open = true;
  }, true);
})();
