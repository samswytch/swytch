/**
 * Inline status change (BRIEF.md §8).
 *
 * The markup is a plain form with a Set button, so it works with JavaScript
 * off. With it on, changing the dropdown submits and the button goes away —
 * one interaction instead of two.
 *
 * The select is deliberately NOT disabled before submitting: a disabled control
 * is left out of the form data, so doing that posts no status at all and the
 * change silently does nothing. A flag guards against a double submit instead.
 */
(function () {
  'use strict';

  var forms = document.querySelectorAll('.status-form');

  Array.prototype.forEach.call(forms, function (form) {
    var select = form.querySelector('select');
    var button = form.querySelector('button');
    if (!select || !button) return;

    var submitting = false;
    button.hidden = true;

    select.addEventListener('change', function () {
      if (submitting) return;
      submitting = true;
      form.submit();
    });
  });
})();
