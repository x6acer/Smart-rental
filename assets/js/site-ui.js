(function () {
  'use strict';

  document.addEventListener('DOMContentLoaded', function () {
    initBrowseFilters();
    initBrowseTabs();
    initSmoothAnchors();
  });

  function initBrowseFilters() {
    var filterForm = document.querySelector('main form.grid');
    var cards = Array.prototype.slice.call(document.querySelectorAll('[data-vehicle-card]'));

    if (!filterForm || cards.length === 0) {
      return;
    }

    filterForm.addEventListener('submit', function (event) {
      event.preventDefault();
      applyBrowseFilters(filterForm, cards);
    });

    filterForm.querySelectorAll('select').forEach(function (select) {
      select.addEventListener('change', function () {
        applyBrowseFilters(filterForm, cards);
      });
    });
  }

  function applyBrowseFilters(form, cards) {
    var typeValue = normalizeText(getFieldValue(form, 'type'));
    var priceValue = normalizeText(getFieldValue(form, 'price'));
    var gearValue = normalizeText(getFieldValue(form, 'gear'));
    var regionValue = normalizeText(getFieldValue(form, 'region'));
    var visibleCount = 0;

    cards.forEach(function (card) {
      var makeModel = normalizeText(card.getAttribute('data-make-model') || '');
      var rate = parseFloat(card.getAttribute('data-rate') || '0');
      var transmission = normalizeText(card.getAttribute('data-transmission') || '');
      var matches = true;

      if (typeValue && typeValue !== 'any type') {
        matches = matches && categoryMatches(typeValue, makeModel);
      }

      if (priceValue && priceValue !== 'any price') {
        matches = matches && priceMatches(priceValue, rate);
      }

      if (gearValue) {
        matches = matches && transmission.indexOf(gearValue) !== -1;
      }

      if (regionValue && regionValue !== 'all regions') {
        matches = matches && card.getAttribute('data-region') === regionValue;
      }

      card.classList.toggle('hidden', !matches);
      if (matches) {
        visibleCount += 1;
      }
    });

    updateEmptyState(visibleCount);
  }

  function categoryMatches(typeValue, makeModel) {
    if (typeValue === 'suv') {
      return /suv|explorer|highlander|fortuner|rav4|pilot/i.test(makeModel);
    }

    if (typeValue === 'sedan') {
      return /sedan|accord|k5|camry|civic|corolla/i.test(makeModel);
    }

    if (typeValue === 'luxury') {
      return /luxury|mercedes|bmw|audi|lexus|range/i.test(makeModel);
    }

    if (typeValue === 'truck') {
      return /truck|pickup|hilux|ranger|navara/i.test(makeModel);
    }

    return true;
  }

  function priceMatches(priceValue, rate) {
    if (priceValue.indexOf('below') !== -1) {
      return rate < 1000;
    }

    if (priceValue.indexOf('above') !== -1) {
      return rate > 2500;
    }

    return rate >= 1000 && rate <= 2500;
  }

  function updateEmptyState(visibleCount) {
    var emptyState = document.getElementById('browseEmptyState');

    if (!emptyState) {
      return;
    }

    emptyState.classList.toggle('hidden', visibleCount > 0);
  }

  function initBrowseTabs() {
    var tabButtons = Array.prototype.slice.call(document.querySelectorAll('#Featured-v button'));
    var cards = Array.prototype.slice.call(document.querySelectorAll('[data-vehicle-card]'));

    if (tabButtons.length === 0 || cards.length === 0) {
      return;
    }

    tabButtons.forEach(function (button) {
      button.addEventListener('click', function () {
        var label = normalizeText(button.textContent || '');

        tabButtons.forEach(function (peer) {
          peer.classList.remove('tab-active');
        });
        button.classList.add('tab-active');

        cards.forEach(function (card) {
          if (label === 'all') {
            card.classList.remove('hidden');
            return;
          }

          var makeModel = normalizeText(card.getAttribute('data-make-model') || '');
          card.classList.toggle('hidden', !categoryMatches(label.replace(/s$/, ''), makeModel));
        });

        updateEmptyState(cards.filter(function (card) {
          return !card.classList.contains('hidden');
        }).length);
      });
    });
  }

  function initSmoothAnchors() {
    document.querySelectorAll('a[href^="#"]').forEach(function (anchor) {
      anchor.addEventListener('click', function (event) {
        var targetId = anchor.getAttribute('href');

        if (!targetId || targetId.length <= 1) {
          return;
        }

        var target = document.querySelector(targetId);

        if (!target) {
          return;
        }

        event.preventDefault();
        target.scrollIntoView({ behavior: 'smooth', block: 'start' });
      });
    });
  }

  function getFieldValue(form, name) {
    var field = form.querySelector('[name="' + name + '"]');
    return field ? field.value : '';
  }

  function normalizeText(value) {
    return String(value || '').trim().toLowerCase();
  }
})();
