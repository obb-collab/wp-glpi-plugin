document.addEventListener('DOMContentLoaded', function () {
  const statusButtons = document.querySelectorAll('.status-filter-btn');
  const executorButtons = document.querySelectorAll('.glpi-filter-btn[data-filter]');
  const searchInput = document.getElementById('glpi-unified-search');
  const cards = document.querySelectorAll('.glpi-card');
  const showAllBtn = document.querySelector('[data-status="all"]');

  // === Удаление активных классов ===
  function clearExecutorActive() {
    executorButtons.forEach(btn => btn.classList.remove('active'));
  }

  // === Получение активных фильтров исполнителей ===
  function getActiveExecutorFilters() {
    return Array.from(executorButtons)
      .filter(btn => btn.classList.contains('active'))
      .map(btn => btn.getAttribute('data-filter'));
  }

  // === Получение активных фильтров статусов ===
  function getActiveStatusFilters() {
    return Array.from(statusButtons)
      .filter(btn => btn !== showAllBtn && btn.classList.contains('active'))
      .map(btn => btn.getAttribute('data-status'));
  }

  // === Фильтрация карточек на основе выбранных фильтров ===
  function filterCards() {
    const searchQuery = searchInput.value.toLowerCase();
    const activeExecutors = getActiveExecutorFilters();
    const activeStatuses = getActiveStatusFilters();
    const showAll = showAllBtn.classList.contains('active');
	
  

    cards.forEach(card => {
      const text = card.innerText.toLowerCase();
      const executors = card.getAttribute('data-executors') || '';
      const status = card.getAttribute('data-status');
      const unassigned = card.getAttribute('data-unassigned') === '1';
      const isLate = card.getAttribute('data-late') === '1';

      let matchSearch = text.includes(searchQuery);
      let matchExecutor = activeExecutors.includes('all') || activeExecutors.length === 0;

      if (activeExecutors.includes('late') && isLate) {
        matchExecutor = true;
      }

      if (!matchExecutor && executors) {
        for (const executor of activeExecutors) {
          if (executors.includes(executor)) {
            matchExecutor = true;
            break;
          }
        }
      }

      let matchStatus = showAll || activeStatuses.length === 0 || activeStatuses.includes(status);
      if (activeStatuses.includes('unassigned') && unassigned) {
        matchStatus = true;
      }

      if (matchSearch && matchExecutor && matchStatus) {
        card.style.display = '';
      } else {
        card.style.display = 'none';
      }
    });
	  updateVisibleCount();
  }

  // === Обработка клика по кнопкам фильтрации статусов ===
  statusButtons.forEach(btn => {
    btn.addEventListener('click', () => {
      const isAll = btn === showAllBtn;

      if (isAll) {
        const wasActive = btn.classList.contains('active');
        // Снимаем всё
        statusButtons.forEach(b => b.classList.remove('active'));

        if (!wasActive) {
          // Включаем "Показать все"
          btn.classList.add('active');
        } else {
          // Возврат к "Назначенные"
          statusButtons.forEach(b => {
            if (b.getAttribute('data-status') === '2') b.classList.add('active');
          });
        }
      } else {
        // Кнопки с data-status
        btn.classList.toggle('active');
        showAllBtn.classList.remove('active');
      }

      filterCards();
    });
  });

  // === Обработка клика по кнопкам фильтрации исполнителей ===
  executorButtons.forEach(btn => {
    btn.addEventListener('click', () => {
      const filter = btn.getAttribute('data-filter');

      if (filter === 'all') {
        clearExecutorActive();
        btn.classList.add('active');
      } else if (filter === 'late') {
        btn.classList.toggle('active');
        executorButtons.forEach(b => {
          if (b.getAttribute('data-filter') === 'all') b.classList.remove('active');
        });
      } else {
        btn.classList.toggle('active');
        executorButtons.forEach(b => {
          if (b.getAttribute('data-filter') === 'all') b.classList.remove('active');
        });
      }

      filterCards();
    });
  });

  // === Поиск: обрабатываем ввод текста ===
  searchInput.addEventListener('input', filterCards);

  // === Инициализация: по умолчанию выбраны только "Назначенные" ===
  statusButtons.forEach(btn => {
    const st = btn.getAttribute('data-status');
    if (st === '2') {
      btn.classList.add('active');
    } else {
      btn.classList.remove('active');
    }
  });
  showAllBtn.classList.remove('active');

  // === Инициализация фильтрации ===
  filterCards();

  // === Логика hover-дропдаунов с задержкой закрытия ===
  document.querySelectorAll('.glpi-filter-dropdown').forEach(dropdown => {
    const toggle = dropdown.querySelector('.glpi-filter-toggle');
    const menu = dropdown.querySelector('.glpi-filter-menu');
    let timeout;

    dropdown.addEventListener('mouseenter', () => {
      clearTimeout(timeout);
      menu.style.display = 'flex';
    });

    dropdown.addEventListener('mouseleave', () => {
      timeout = setTimeout(() => {
        menu.style.display = 'none';
      }, 250); // Задержка перед схлопыванием
    });
  });
});

function pluralizeDays(n) {
    if (n % 10 === 1 && n % 100 !== 11) return 'день';
    if ([2, 3, 4].includes(n % 10) && ![12, 13, 14].includes(n % 100)) return 'дня';
    return 'дней';
}

function getAgeClass(days) {
    if (days >= 90) return 'age-black';
    if (days >= 22) return 'age-blue';
    if (days >= 15) return 'age-orange';
    if (days >= 8) return 'age-red';
    return 'age-green';
}

function updateDateFooters() {
    document.querySelectorAll('.glpi-date-footer').forEach(el => {
        const rawDate = el.dataset.date;
        if (rawDate) {
            const days = Math.floor((Date.now() - new Date(rawDate)) / (1000 * 60 * 60 * 24));
            const ageClass = getAgeClass(days);

            // Сначала убираем старые классы
            el.classList.remove('age-red', 'age-yellow', 'age-green', 'age-blue', 'age-orange', 'age-black');
            el.classList.add(ageClass);

            // Обновляем текст
            el.innerHTML = `⏱ ${days} ${pluralizeDays(days)}`;
        }
    });
}

// Вызов после загрузки
document.addEventListener('DOMContentLoaded', () => {
    updateDateFooters();
});

function updateVisibleCount() {
  const visibleCards = Array.from(document.querySelectorAll('.glpi-card'))
    .filter(card => card.style.display !== 'none');

  const counterBtn = document.getElementById('glpi-counter');
  if (counterBtn) {
    counterBtn.textContent = `В фильтре: ${visibleCards.length}`;
  }
}

