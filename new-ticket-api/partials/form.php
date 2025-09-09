<button type="button" class="nta-open-modal">Новая заявка (API)</button>
<div class="nta-wrap" aria-hidden="true" role="dialog" aria-modal="true">
  <div class="nta-modal">
    <button type="button" class="nta-close-modal" aria-label="Закрыть">×</button>
    <form class="nta-form" novalidate>
      <label>Тема
        <input type="text" name="title" required minlength="3" maxlength="255" />
      </label>
      <label>Описание
        <textarea name="content" required minlength="3" maxlength="65535"></textarea>
      </label>
      <div class="nta-inline">
        <label data-nta-field="category">Категория
          <div class="nta-lookup-wrap" data-nta-lookup="category">
            <input type="text" class="nta-lookup-input" placeholder="Начните вводить..." />
            <button type="button" class="nta-lookup-toggle" aria-label="Показать список"></button>
            <div class="nta-lookup-list"></div>
          </div>
          <input type="hidden" name="category_id" />
          <div class="nta-note" data-nta-note="category" hidden></div>
          <div class="nta-error" data-nta-error="category" hidden></div>
        </label>
        <label data-nta-field="location">Местоположение
          <div class="nta-lookup-wrap" data-nta-lookup="location">
            <input type="text" class="nta-lookup-input" placeholder="Начните вводить..." />
            <button type="button" class="nta-lookup-toggle" aria-label="Показать список"></button>
            <div class="nta-lookup-list"></div>
          </div>
          <input type="hidden" name="location_id" />
          <div class="nta-note" data-nta-note="location" hidden></div>
          <div class="nta-error" data-nta-error="location" hidden></div>
        </label>
      </div>
      <label><input type="checkbox" name="self_assign" class="nta-self" checked /> Я исполнитель</label>
      <label data-nta-field="assignee">Исполнитель
        <select name="assignee_id"></select>
        <div class="nta-error" data-nta-error="assignee" hidden></div>
      </label>
      <button type="submit" class="nta-submit" disabled>Создать заявку</button>
      <div class="nta-submit-error"></div>
      <div class="nta-success nta-submit-success"></div>
    </form>
  </div>
</div>
