/**
 * CrawlWP SEO - Elementor Integration
 */
(function($) {
  'use strict';

  var VAR_CONFIGS = {
    title: [
      { token: '{{ post.title }}', label: 'Post title' },
      { token: '{{ site.title }}', label: 'Site name' },
      { token: '{{ sep }}', label: 'Separator' },
      { token: '{{ post.category }}', label: 'Primary category' },
      { token: '{{ post.auto_description }}', label: 'Post excerpt' },
      { token: '{{ current.year }}', label: 'Current year' },
      { token: '{{ post.author }}', label: 'Author name' },
      { token: '{{ post.date }}', label: 'Publish date' },
      { token: '{{ post.modified }}', label: 'Modified date' }
    ],
    description: [
      { token: '{{ post.auto_description }}', label: 'Post excerpt' },
      { token: '{{ post.title }}', label: 'Post title' },
      { token: '{{ site.title }}', label: 'Site name' },
      { token: '{{ post.category }}', label: 'Primary category' },
      { token: '{{ current.year }}', label: 'Current year' },
      { token: '{{ post.author }}', label: 'Author name' },
      { token: '{{ sep }}', label: 'Separator' }
    ]
  };

  var TARGET_CONTROLS = {
    '_crawlwp_seo_title': 'title',
    '_crawlwp_seo_description': 'description',
    '_crawlwp_og_title': 'title',
    '_crawlwp_og_description': 'description',
    '_crawlwp_x_title': 'title',
    '_crawlwp_x_description': 'description',
    '_crawlwp_schema_headline': 'title'
  };

  var SEO = {
    init: function() {
      var self = this;

      this.bindUIEvents();

      $(window).on('elementor:init', function() {
        self.initHooks();
      });

      if (window.elementor) {
        this.initHooks();
      }
    },

    initHooks: function() {
      var self = this;
      if (!window.elementor) return;

      if (elementor.hooks && elementor.hooks.addAction) {
        elementor.hooks.addAction('panel/open_editor/settings', function() {
          setTimeout(function() {
            self.attachAllInserters();
          }, 100);
        });
      }

      if (elementor.hooks && elementor.hooks.addFilter) {
        elementor.hooks.addFilter('controls/base/behaviors', function(behaviors, view) {
          if (view && view.options && view.options.model) {
            var name = view.options.model.get('name');
            if (TARGET_CONTROLS[name]) {
              setTimeout(function() {
                if (view.$el) {
                  self.injectVariableInserter(view.$el, TARGET_CONTROLS[name]);
                }
              }, 50);
            }
          }
          return behaviors;
        });
      }

      // Observe panel DOM for tab changes or dynamic control rendering
      var panelEl = document.getElementById('elementor-panel');
      if (panelEl && window.MutationObserver) {
        var observer = new MutationObserver(function() {
          self.attachAllInserters();
        });
        observer.observe(panelEl, { childList: true, subtree: true });
      }

      setTimeout(function() {
        self.attachAllInserters();
      }, 200);
    },

    buildVarHtml: function(varType) {
      var data = window.crawlwpSEO || {};
      var btnLabel = (data.i18n && data.i18n.insertVariable) ? data.i18n.insertVariable : 'Insert variable';
      var vars = (VAR_CONFIGS[varType] || VAR_CONFIGS.title).slice();

      if (data.product) {
        vars = vars.concat([
          { token: '{{ product.price }}', label: 'Price' },
          { token: '{{ product.price_with_tax }}', label: 'Price including tax' },
          { token: '{{ product.sale_from }}', label: 'Sale price date "From"' },
          { token: '{{ product.sale_to }}', label: 'Sale price date "To"' },
          { token: '{{ product.sku }}', label: 'SKU' },
          { token: '{{ product.stock }}', label: 'Stock status' },
          { token: '{{ product.currency }}', label: 'Currency' },
          { token: '{{ product.rating }}', label: 'Rating value' },
          { token: '{{ product.review_count }}', label: 'Review count' },
          { token: '{{ product.low_price }}', label: 'Low price (variable product)' },
          { token: '{{ product.high_price }}', label: 'High price (variable product)' },
          { token: '{{ product.offer_count }}', label: 'Offer count (variable product)' }
        ]);
      }

      var itemsHtml = '';
      $.each(vars, function(i, item) {
        itemsHtml += '<button class="cwp-var-item" type="button" data-token="' + item.token + '">' +
          '<code>' + item.token + '</code>' +
          '<span>' + item.label + '</span>' +
          '</button>';
      });

      return '<div class="cwp-var">' +
        '<button class="cwp-var-btn" type="button" title="' + btnLabel + '">' +
        '<svg width="10" height="10" viewBox="0 0 10 10"><path d="M5 1v8M1 5h8" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/></svg>' +
        '<span>' + btnLabel + '</span>' +
        '</button>' +
        '<div class="cwp-var-menu">' + itemsHtml + '</div>' +
        '</div>';
    },

    injectVariableInserter: function($control, varType) {
      if (!$control.length || $control.find('.cwp-var').length) {
        return;
      }

      var $title = $control.find('.elementor-control-title');
      var $varEl = $(this.buildVarHtml(varType));

      if ($title.length) {
        $title.after($varEl);
      } else {
        var $field = $control.find('.elementor-control-field');
        if ($field.length) {
          $field.prepend($varEl);
        }
      }
    },

    attachAllInserters: function() {
      var self = this;
      $.each(TARGET_CONTROLS, function(settingKey, varType) {
        var $control = $('.elementor-control-' + settingKey);
        if ($control.length && !$control.find('.cwp-var').length) {
          self.injectVariableInserter($control, varType);
        }
      });
    },

    bindUIEvents: function() {
      var self = this;

      // Toggle variable menu
      $(document).on('click', '.cwp-var-btn', function(e) {
        e.preventDefault();
        e.stopPropagation();
        var $btn = $(this);
        var $menu = $btn.siblings('.cwp-var-menu');
        $('.cwp-var-menu').not($menu).removeClass('is-open');
        $menu.toggleClass('is-open');
      });

      // Close menu when clicking outside
      $(document).on('click', function(e) {
        if (!$(e.target).closest('.cwp-var').length) {
          $('.cwp-var-menu').removeClass('is-open');
        }
      });

      // Close menu on Escape key
      $(document).on('keydown', function(e) {
        if (e.which === 27) {
          $('.cwp-var-menu').removeClass('is-open');
        }
      });

      // Click variable item -> insert token at cursor / end
      $(document).on('click', '.cwp-var-item', function(e) {
        e.preventDefault();
        e.stopPropagation();

        var $item = $(this);
        var token = $item.data('token');
        var $menu = $item.closest('.cwp-var-menu');
        var $control = $item.closest('.elementor-control');
        var $input = $control.find('input, textarea');

        if ($input.length) {
          var el = $input[0];
          var start = (typeof el.selectionStart === 'number') ? el.selectionStart : el.value.length;
          var end = (typeof el.selectionEnd === 'number') ? el.selectionEnd : start;
          var currentVal = el.value || '';
          var newVal = currentVal.substring(0, start) + token + currentVal.substring(end);

          el.value = newVal;
          $input.trigger('input').trigger('change');

          var settingKey = $input.data('setting');
          if (settingKey && window.elementor && elementor.settings && elementor.settings.page) {
            elementor.settings.page.model.set(settingKey, newVal);
          }

          el.focus();
          var newPos = start + token.length;
          if (typeof el.setSelectionRange === 'function') {
            el.setSelectionRange(newPos, newPos);
          }
        }

        $menu.removeClass('is-open');
      });
    }
  };

  $(function() {
    SEO.init();
  });

})(jQuery);
