/**
 * gexe-executor-lock.js — EMERGENCY SAFE MODE
 * НУЛЕВОЙ файл: не вмешивается в боевую логику, не навешивает observers/intervals,
 * не перемещает элементы и не меняет inline-стили.
 *
 * Положи этот файл вместо текущего, затем Ctrl+F5. Это откатит все мои агрессивные правки.
 */

(function () {
  'use strict';

  // Emergency safe marker, чтобы было видно в консоли при отладке
  try {
    window.__gexe_executor_lock_mode = 'safe-noop';
  } catch (e) {}

  // Ничего не делаем — сохраним боевую логику нетронутой.
})();
