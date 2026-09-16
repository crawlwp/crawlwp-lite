(function($){

  'use strict';

  /**
   * CrawlWP SEO Metabox controller.
   *
   * Implemented as a plain JavaScript object (no Class syntax) with inner
   * methods. Expensive work such as analysis and link rendering is not called
   * directly from every input handler. Instead handlers dispatch custom events
   * on the metabox element and dedicated listeners react to them:
   *
   *   crawlwp:sync        -> refresh the live previews
   *   crawlwp:measure     -> recalculate pixel meters
   *   crawlwp:renderLinks -> rebuild the Links panel
   *   crawlwp:analyze     -> re-run the Analysis panel
   */
  var CrawlWP = {

    /* ---------- state ---------- */
    $mb: null,
    TOKENS: {},
    ctx: null,

    /* cached fields (populated in cacheElements) */
    $title: null,
    $desc: null,
    $fbSync: null,
    $fbTitle: null,
    $fbDesc: null,
    $xSync: null,
    $xTitle: null,
    $xDesc: null,
    $jsonPre: null,
    $pageType: null,
    $articleType: null,
    $schemaHeadline: null,
    $schemaSection: null,
    activeKwIndex: 0,
    analysisResults: [],

    /* ---------- bootstrap ---------- */
    init: function() {
      this.$mb = $('#crawlwp-seo-metabox-inner');
      if (!this.$mb.length) return;

      this.ctx = $('<canvas>')[0].getContext('2d');

      this.setupTokens();
      this.cacheElements();
      this.bindEventBus();
      this.bindUi();
      this.watchPostTitle();
      this.watchPostSlug();
      this.watchPostContent();
      this.watchFeaturedImage();

      /* initial paint */
      this.emit('measure');
      this.emit('sync');
      this.toggleSync();
      this.updateSchemaPreview();
      this.updateBreadcrumbPreview();
      this.initSocialImagePlaceholders();
      this.validateExistingImages();
      this.emit('renderLinks');
      this.emit('analyze');
    },

    setupTokens: function() {
      crawlwpSEO.postTitle = this.decodeEntities(crawlwpSEO.postTitle || '');
      this.TOKENS = {
        'post.title':            crawlwpSEO.postTitle,
        'site.title':            crawlwpSEO.siteName || '',
        'sep':                   crawlwpSEO.separator || '\u2014',
        'post.category':         crawlwpSEO.category || '',
        'post.auto_description': crawlwpSEO.excerpt || '',
        'current.year':          crawlwpSEO.currentYear || '',
        'post.author':           crawlwpSEO.author || ''
      };

      if (crawlwpSEO.product && typeof crawlwpSEO.product === 'object') {
        var self = this;
        $.each(crawlwpSEO.product, function(key, val) {
          self.TOKENS['product.' + key] = val != null ? String(val) : '';
        });
      }
    },

    cacheElements: function() {
      this.$title = $('#cwpTitle');
      this.$desc  = $('#cwpDesc');

      this.$fbSync  = $('#cwpFbSync');
      this.$fbTitle = $('#cwpFbTitle');
      this.$fbDesc  = $('#cwpFbDesc');
      this.$xSync   = $('#cwpXSync');
      this.$xTitle  = $('#cwpXTitle');
      this.$xDesc   = $('#cwpXDesc');

      this.$jsonPre        = $('#cwpJson');
      this.$pageType       = $('#cwpPageType');
      this.$articleType    = $('#cwpArticleType');
      this.$schemaHeadline = $('#cwpHeadline');
      this.$schemaSection  = $('#cwpSection');
    },

    /* ---------- event bus ---------- */
    /* Listeners are registered once; input handlers only emit events. */
    bindEventBus: function() {
      var self = this;
      this.$mb.on('crawlwp:sync',        function() { self.sync(); });
      this.$mb.on('crawlwp:measure',     function() { self.measureAll(); });
      this.$mb.on('crawlwp:renderLinks', function() { self.renderLinks(); });
      this.$mb.on('crawlwp:analyze',     function() { self.runAnalysis(); });
    },

    /* dispatch a namespaced event on the metabox element */
    emit: function(name) {
      this.$mb.trigger('crawlwp:' + name);
    },

    /* {{ post.title }} / {{post.title}} -> its value; unknown tokens drop out,
       matching the PHP resolver used on the frontend. */
    resolve: function(str) {
      var tokens = this.TOKENS;
      return String(str == null ? '' : str).replace(/\{\{\s*([a-z0-9_.]+)\s*\}\}/gi, function(m, token) {
        var key = token.toLowerCase();
        return tokens[key] !== undefined ? tokens[key] : '';
      });
    },

    /* ---------- UI wiring ---------- */
    bindUi: function() {
      var self = this;

      /* tabs */
      this.$mb.find('.cwp-tab').on('click', function() {
        self.$mb.find('.cwp-tab').removeClass('is-active').attr('aria-selected', 'false');
        self.$mb.find('.cwp-panel').removeClass('is-active');
        $(this).addClass('is-active').attr('aria-selected', 'true');
        $('#cwp-panel-' + $(this).data('panel')).addClass('is-active');

        /* re-scan when switching to links/analysis */
        if ($(this).data('panel') === 'links') self.emit('renderLinks');
        if ($(this).data('panel') === 'analysis') self.emit('analyze');

        /* Notify add-on plugins (e.g. mihdan-index-now-pro) that a tab
           became active, so they can lazy-load their own panel's data
           instead of fetching it on every page load. */
        $(document).trigger('crawlwp:metabox:tabActivated', [$(this).data('panel')]);
      });

      /* social sub-tabs */
      this.$mb.find('.cwp-social-tab').on('click', function() {
        self.$mb.find('.cwp-social-tab').removeClass('is-active');
        self.$mb.find('.cwp-social-panel').removeClass('is-active');
        $(this).addClass('is-active');
        $('#cwp-social-' + $(this).data('social')).addClass('is-active');
      });

      /* device toggle */
      var $serp = $('#cwpSerp');
      var $deviceSeg = $('#cwpDeviceSeg');
      if ($deviceSeg.length) {
        $deviceSeg.on('click', function(e) {
          var $btn = $(e.target).closest('button');
          if (!$btn.length) return;
          $deviceSeg.find('button').removeClass('is-active');
          $btn.addClass('is-active');
          $serp.toggleClass('is-mobile', $btn.data('device') === 'mobile');
          self.emit('measure');
        });
      }

      /* variable menus */
      $(document).on('click', function(e) {
        var $btn = $(e.target).closest('.cwp-var-btn');
        $('.cwp-var-menu').each(function() {
          var $m = $(this);
          if (!$btn.length || $m.prev()[0] !== $btn[0]) $m.removeClass('is-open');
        });
        if ($btn.length) $btn.next().toggleClass('is-open');

        var $item = $(e.target).closest('.cwp-var-item');
        if ($item.length) {
          var $menu = $item.closest('.cwp-var-menu');
          var $target = $('#' + $menu.prev().data('varFor'));
          if (!$target.length) return;
          var target = $target[0];
          var start = target.selectionStart || target.value.length;
          target.value = target.value.slice(0, start) + $item.data('token') + target.value.slice(target.selectionEnd || start);
          target.focus();
          target.selectionStart = target.selectionEnd = start + $item.data('token').length;
          $menu.removeClass('is-open');
          $target.trigger('input');
        }
      });

      /* live preview binding */
      this.$fbSync.add(this.$xSync).on('change', function() { self.toggleSync(); });
      this.$fbTitle.add(this.$fbDesc).add(this.$xTitle).add(this.$xDesc).on('input', function() { self.emit('sync'); });

      this.$title.add(this.$desc).on('input', function() {
        self.measure(this);
        self.emit('sync');
        self.emit('analyze');
      });

      /* focus keyword drives both analysis and suggested links */
      var _kwDebounce = null;
      $('#cwpKeyword').on('input', function() {
        var keyword = $(this).val();
        self.emit('analyze');
        self.emit('renderLinks');
        clearTimeout(_kwDebounce);
        _kwDebounce = setTimeout(function() {
          self.checkDuplicateKeyword();
          self.refreshSuggestedLinks(keyword);
        }, 600);
      });

      /* keyword tabs switcher */
      $('#cwpKwTabs').on('click', '.cwp-kw-tab', function(e) {
        e.preventDefault();
        var idx = parseInt($(this).data('kwIndex'), 10);
        if (!isNaN(idx)) {
          self.activeKwIndex = idx;
          self.renderAnalysisTabs();
        }
      });

      /* breadcrumb label updates preview */
      $('#cwpBreadcrumb').on('input', function() {
        self.updateBreadcrumbPreview();
      });

      /* JSON-LD toggle */
      var $jsonBtn = $('#cwpJsonToggle');
      if ($jsonBtn.length && this.$jsonPre.length) {
        var $pre = this.$jsonPre;
        $jsonBtn.on('click', function() {
          var open = $pre.prop('hidden');
          $pre.prop('hidden', !open);
          $jsonBtn.text(open ? crawlwpSEO.i18n.hideJsonLd : crawlwpSEO.i18n.showJsonLd);
        });
      }

      /* schema type / fields update the preview */
      this.$pageType.add(this.$articleType).on('change', function() { self.updateSchemaPreview(); });
      if (this.$schemaHeadline.length) this.$schemaHeadline.on('input', function() { self.updateSchemaPreview(); });
      if (this.$schemaSection.length) this.$schemaSection.on('input', function() { self.updateSchemaPreview(); });

      /* image pickers (with dimension validation) */
      this.bindImagePickers();

      /* AI generate buttons */
      this.$mb.find('.cwp-ai-btn').on('click', function() {
        var $btn = $(this);
        var field = $btn.data('aiField');

        /* Legacy markup only carried the target element id. */
        if (!field) {
          field = $btn.data('aiTarget') === 'cwpTitle' ? 'title' : 'description';
        }

        self.aiGenerate(field, $btn);
      });

      /* Keep the button label honest: a field with a value gets rewritten. */
      this.$mb.find('.cwp-ai-btn').each(function() {
        self.aiSyncLabel($(this));
      });
      this.$mb.on('input change', '[id^=cwp]', function() {
        var $btn = self.$mb.find('.cwp-ai-btn[data-ai-target="' + this.id + '"]');
        if ($btn.length) self.aiSyncLabel($btn);
      });

      /* IndexNow submit button */
      $('#cwpSubmitIndexNow').on('click', function() {
        self.submitIndexNow($(this));
      });
    },

    /* ---------- pixel measurement ---------- */
    widthOf: function(text, font) {
      this.ctx.font = font;
      return Math.round(this.ctx.measureText(text).width);
    },

    measure: function(el) {
      var $el = $(el);
      var raw = $el.val();
      /* If this is the SEO title field and it is empty, assume the default template */
      if (!raw && $el.attr('id') === 'cwpTitle') {
        raw = '{{ post.title }} {{ sep }} {{ site.title }}';
      }
      var text  = this.resolve(raw);
      var limit = parseInt($el.data('limit'), 10);
      var px    = this.widthOf(text, $el.data('font'));
      var pct   = Math.min(100, Math.round(px / limit * 100));
      var $fill  = $('#' + $el.data('meter') + 'Fill');
      var $label = $('#' + $el.data('meter'));

      $fill.css('width', pct + '%').removeClass('is-good is-over');
      var state = 'short';
      if (px > limit) { $fill.addClass('is-over'); state = 'over'; }
      else if (pct >= 70) { $fill.addClass('is-good'); state = 'good'; }

      var L = crawlwpSEO.i18n;
      var words = { short: L.meterTooShort, good: L.meterGoodLength, over: L.meterWillBeCut }[state];
      $label.html('<b>' + words + '</b> \u00b7 ' + this.fmt(L.meterDetail, px, limit, text.length));
    },

    measureAll: function() {
      var self = this;
      this.$mb.find('[data-meter]').each(function() { self.measure(this); });
    },

    /* ---------- live preview ---------- */
    sync: function() {
      var L = crawlwpSEO.i18n;
      var titleVal = this.$title.val() || '{{ post.title }} {{ sep }} {{ site.title }}';
      var t = this.resolve(titleVal) || crawlwpSEO.postTitle || L.enterTitle;
      var d = this.resolve(this.$desc.val())  || crawlwpSEO.excerpt || L.addMetaDesc;

      $('#cwpSerpTitle').text(t);
      $('#cwpSerpDesc').text(d);

      var urlParts = crawlwpSEO.siteUrl.replace(/^https?:\/\//, '').replace(/\/$/, '');
      $('#cwpSerpUrl').text(urlParts + ' \u203a ' + (this.getSlug() || '\u2026'));

      /* social mirrors */
      var fbT = this.$fbSync.prop('checked') ? t : (this.$fbTitle.val() || t);
      var fbD = this.$fbSync.prop('checked') ? d : (this.$fbDesc.val()  || d);
      $('#cwpFbTitlePrev').text(fbT);
      $('#cwpFbDescPrev').text(fbD);

      $('#cwpXTitlePrev').text(this.$xSync.prop('checked') ? fbT : (this.$xTitle.val() || fbT));
      $('#cwpXDescPrev').text(this.$xSync.prop('checked') ? fbD : (this.$xDesc.val()  || fbD));

      this.updateBreadcrumbPreview();
    },

    toggleSync: function() {
      this.$fbTitle.prop('disabled', this.$fbSync.prop('checked'));
      this.$fbDesc.prop('disabled', this.$fbSync.prop('checked'));
      this.$xTitle.prop('disabled', this.$xSync.prop('checked'));
      this.$xDesc.prop('disabled', this.$xSync.prop('checked'));

      /* Nothing to generate into while the field mirrors another value. */
      var self = this;
      this.$mb.find('.cwp-ai-btn[data-ai-target]').each(function() {
        var $btn = $(this);
        var $target = $('#' + $btn.data('aiTarget'));
        if ($target.length) {
          $btn.prop('disabled', $target.prop('disabled'));
        }
      });

      this.emit('sync');
    },

    /* Mirrors FrontendOutput::output_schema(): the article type wins over the
       page type unless it is set to "none". */
    updateSchemaPreview: function() {
      if (!this.$jsonPre.length) return;

      var pageType    = (this.$pageType.length ? this.$pageType.val() : '') || 'WebPage';
      var articleType = (this.$articleType.length ? this.$articleType.val() : '') || '';
      var type        = (articleType && articleType !== 'none') ? articleType : pageType;

      if (!type || type === 'none') {
        this.$jsonPre.text(crawlwpSEO.i18n.noStructuredData);
        return;
      }

      var headline = (this.$schemaHeadline.length && this.$schemaHeadline.val()) || this.resolve(this.$title.val()) || crawlwpSEO.postTitle;
      var section  = this.$schemaSection.length ? this.$schemaSection.val() : '';
      var schema = {
        '@context': 'https://schema.org',
        '@type': type,
        'headline': headline
      };
      if (crawlwpSEO.permalink) schema.url = crawlwpSEO.permalink;
      if (section) schema.articleSection = section;
      if (crawlwpSEO.author) {
        schema.author = { '@type': 'Person', 'name': crawlwpSEO.author };
      }
      if (crawlwpSEO.datePublished) schema.datePublished = crawlwpSEO.datePublished;
      if (crawlwpSEO.dateModified)  schema.dateModified  = crawlwpSEO.dateModified;
      this.$jsonPre.text(JSON.stringify(schema, null, 2));
    },

    /* ---------- read the current slug straight from WP (no metabox field) ---------- */
    getSlug: function() {
      /* Gutenberg */
      if (typeof wp !== 'undefined' && wp.data && wp.data.select && wp.data.select('core/editor')) {
        var sel = wp.data.select('core/editor');
        var slug = sel ? sel.getEditedPostAttribute('slug') : '';
        if (slug) return slug;
      }
      /* Classic editor: #post_name input (permalink editor) */
      var $wpSlug = $('#post_name');
      if ($wpSlug.length && $wpSlug.val()) return $wpSlug.val();
      /* Classic editor: read-only permalink display */
      var editSlug = document.getElementById('editable-post-name');
      if (editSlug) {
        var text = $.trim($(editSlug).text());
        if (text) return text;
      }
      return '';
    },

    /* ---------- watch WP post title -> metabox ---------- */
    watchPostTitle: function() {
      var self = this;

      /* Classic editor */
      var $wpTitle = $('#title');
      if ($wpTitle.length) {
        $wpTitle.on('input', function() {
          var val = $wpTitle.val();
          self.TOKENS['post.title'] = val;
          crawlwpSEO.postTitle = val;
          $('#cwpBreadcrumb').attr('placeholder', val);
          $('#cwpHeadline').attr('placeholder', val);
          self.emit('measure');
          self.emit('sync');
          self.emit('analyze');
        });
      }

      /* Gutenberg */
      if (typeof wp !== 'undefined' && wp.data && wp.data.subscribe && wp.data.select('core/editor')) {
        var lastTitle = this.TOKENS['post.title'];
        wp.data.subscribe(function() {
          var sel = wp.data.select('core/editor');
          if (!sel) return;
          var newTitle = sel.getEditedPostAttribute('title');
          if (newTitle !== undefined && newTitle !== lastTitle) {
            lastTitle = newTitle;
            self.TOKENS['post.title'] = newTitle;
            crawlwpSEO.postTitle = newTitle;
            $('#cwpBreadcrumb').attr('placeholder', newTitle);
            $('#cwpHeadline').attr('placeholder', newTitle);
            self.emit('measure');
            self.emit('sync');
            self.emit('analyze');
          }
        });
      }
    },

    /* ---------- watch WP's own slug controls -> refresh previews ---------- */
    /* There is no metabox slug field anymore (removed) -- the search preview
       reads the slug live via getSlug(), so this just needs to know when to
       refresh it. */
    watchPostSlug: function() {
      var self = this;

      /* Classic editor: #post_name */
      var wpSlugInput = document.getElementById('post_name');
      if (wpSlugInput) {
        new MutationObserver(function() {
          self.emit('sync');
          self.emit('analyze');
        }).observe(wpSlugInput, { attributes: true, attributeFilter: ['value'] });
        $(wpSlugInput).on('change', function() {
          self.emit('sync');
          self.emit('analyze');
        });
      }

      /* Classic editor: editable slug span */
      var editSlug = document.getElementById('editable-post-name');
      if (editSlug) {
        new MutationObserver(function() {
          self.emit('sync');
          self.emit('analyze');
        }).observe(editSlug, { childList: true, characterData: true, subtree: true });
      }

      /* Gutenberg */
      if (typeof wp !== 'undefined' && wp.data && wp.data.subscribe && wp.data.select('core/editor')) {
        var lastSlug = this.getSlug();
        wp.data.subscribe(function() {
          var sel = wp.data.select('core/editor');
          if (!sel) return;
          var newSlug = sel.getEditedPostAttribute('slug');
          if (newSlug !== undefined && newSlug !== lastSlug) {
            lastSlug = newSlug;
            self.emit('sync');
            self.emit('analyze');
          }
        });
      }
    },

    /* ---------- watch WP post content -> metabox ---------- */
    watchPostContent: function() {
      var self = this;
      var _contentDebounce = null;
      function onContentChange() {
        clearTimeout(_contentDebounce);
        _contentDebounce = setTimeout(function() {
          self.emit('renderLinks');
          self.emit('analyze');
        }, 500);
      }

      /* Classic editor: TinyMCE */
      if (typeof tinymce !== 'undefined') {
        var tryBind = function() {
          var ed = tinymce.get('content');
          if (ed) {
            ed.on('input change keyup Undo Redo', onContentChange);
            /* TinyMCE loads the post content into its iframe asynchronously,
               after this metabox has already run its initial analysis (see
               getEditorContent(), which falls back to the raw #content
               textarea until ed.initialized is true). Once TinyMCE actually
               finishes loading, re-run analysis immediately so an existing
               post shows real Readability/Analysis results without the user
               having to type anything first. */
            if (ed.initialized) {
              onContentChange();
            } else {
              ed.on('init', onContentChange);
            }
          } else {
            setTimeout(tryBind, 500);
          }
        };
        tryBind();
      }

      /* Classic editor: plain-text textarea fallback */
      var $contentTA = $('#content');
      if ($contentTA.length) {
        $contentTA.on('input', onContentChange);
      }

      /* Gutenberg */
      if (typeof wp !== 'undefined' && wp.data && wp.data.subscribe && wp.data.select('core/editor')) {
        var lastContent = '';
        var sel = wp.data.select('core/editor');
        if (sel) {
          lastContent = sel.getEditedPostContent() || '';
        }
        wp.data.subscribe(function() {
          var s = wp.data.select('core/editor');
          if (!s) return;
          var newContent = s.getEditedPostContent() || '';
          if (newContent !== lastContent) {
            lastContent = newContent;
            onContentChange();
          }
        });
      }
    },

    /* ---------- AI generation ---------- */

    /* Which input each generatable field writes into. */
    aiTargets: {
      'title':          '#cwpTitle',
      'description':    '#cwpDesc',
      'og_title':       '#cwpFbTitle',
      'og_description': '#cwpFbDesc',
      'x_title':        '#cwpXTitle',
      'x_description':  '#cwpXDesc'
    },

    /* The tooltip tells the user whether the value will be written or rewritten. */
    aiSyncLabel: function($btn) {
      var L = crawlwpSEO.i18n;
      var $target = $('#' + $btn.data('aiTarget'));
      var hasValue = $target.length && $.trim($target.val() || '') !== '';

      $btn.attr('title', hasValue && L.aiRewrite ? L.aiRewrite : L.aiGenerate);
    },

    aiGenerate: function(field, $btn) {
      var self = this;
      var L = crawlwpSEO.i18n;
      var selector = this.aiTargets[field];
      var $target = selector ? $(selector) : $();

      if (!$target.length) return;

      if ($btn.hasClass('is-loading')) return;

      $btn.addClass('is-loading').prop('disabled', true);
      var origHtml = $btn.html();
      $btn.html('<span class="cwp-ai-spinner"></span> ' + L.aiGenerating);

      var postTitle = crawlwpSEO.postTitle || '';
      if (typeof wp !== 'undefined' && wp.data && wp.data.select('core/editor')) {
        var sel = wp.data.select('core/editor');
        if (sel) postTitle = sel.getEditedPostAttribute('title') || postTitle;
      } else {
        var $wpTitle = $('#title');
        if ($wpTitle.length) postTitle = $wpTitle.val() || postTitle;
      }

      var content = this.getEditorContent();
      var keyword = $('#cwpKeyword').val() || '';

      $.ajax({
        url: crawlwpSEO.ajaxUrl,
        type: 'POST',
        data: {
          action: 'crawlwp_ai_generate',
          nonce: crawlwpSEO.aiNonce,
          post_id: crawlwpSEO.postId,
          field: field,
          post_title: postTitle,
          post_content: content,
          focus_keyword: keyword,
          /* Sent so the model rewrites instead of repeating itself. */
          previous_value: $target.val() || ''
        },
        success: function(resp) {
          if (resp.success && resp.data && resp.data.text) {
            $target.val(resp.data.text).trigger('input').trigger('change');
            self.aiSyncLabel($btn);
            return;
          }

          /* No AI provider connected, or the request failed: there is no
             fallback, so tell the user how to fix it. */
          self.aiShowError(resp.data);
        },
        error: function() {
          self.aiShowError(null);
        },
        complete: function() {
          $btn.removeClass('is-loading').prop('disabled', false).html(origHtml);
        }
      });
    },

    aiShowError: function(data) {
      var L = crawlwpSEO.i18n;
      var msg = (data && data.message) ? data.message : L.aiError;

      window.alert(msg);

      /* Offer to open the WordPress Connectors screen in a new tab so the
         user can connect an AI provider without losing their edits. */
      if (data && data.connectUrl && window.confirm(L.aiOpenConnectors)) {
        var win = window.open(data.connectUrl, '_blank');
        if (win) {
          win.opener = null;
          win.focus();
        } else {
          /* Popup blocked: fall back to the current tab, the user already
             agreed to open the page. */
          window.location.href = data.connectUrl;
        }
      }
    },

    /* ---------- IndexNow submit ---------- */
    submitIndexNow: function($btn) {
      var self = this;
      var L = crawlwpSEO.i18n;

      if (!crawlwpSEO.postId) {
        self.indexNowShowStatus(L.savePostFirst, 'warn');
        return;
      }

      if ($btn.hasClass('is-loading')) return;

      $btn.addClass('is-loading').prop('disabled', true).text(L.submitting);

      $.ajax({
        url: crawlwpSEO.ajaxUrl,
        type: 'POST',
        data: {
          action: 'crawlwp_submit_indexnow',
          nonce: crawlwpSEO.indexNowNonce,
          post_id: crawlwpSEO.postId
        },
        success: function(resp) {
          if (resp.success && resp.data) {
            self.indexNowShowStatus(L.submitSuccess, 'success');
          } else {
            self.indexNowShowStatus(L.submitError, 'error');
          }
        },
        error: function() {
          self.indexNowShowStatus(L.submitError, 'error');
        },
        complete: function() {
          $btn.removeClass('is-loading').prop('disabled', false).text(L.submitIndexNow);
        }
      });
    },

    indexNowShowStatus: function(msg, type) {
      var $btn = $('#cwpSubmitIndexNow');
      var $status = $btn.siblings('.cwp-indexnow-status');
      if (!$status.length) {
        $status = $('<div class="cwp-indexnow-status"></div>').insertAfter($btn);
      }
      $status.text(msg)
        .removeClass('is-success is-error is-warn')
        .addClass('is-' + type)
        .show();
      setTimeout(function() { $status.fadeOut(300); }, 4000);
    },

    /* ---------- image pickers (WP media) ---------- */
    bindImagePickers: function() {
      var self = this;

      this.$mb.find('.cwp-img-pick-btn').on('click', function(e) {
        e.preventDefault();
        var $btn = $(this);
        var target   = $btn.data('target');
        var $inputEl = $('#' + target);
        var $thumbEl = $btn.closest('.cwp-img-picker').find('.cwp-img-thumb');

        var frame = wp.media({
          title: crawlwpSEO.i18n.selectImage,
          multiple: false,
          library: { type: 'image' }
        });

        frame.on('select', function() {
          var attachment = frame.state().get('selection').first().toJSON();
          $inputEl.val(attachment.id);
          if ($thumbEl.length && attachment.url) {
            $thumbEl.css('backgroundImage', 'url(' + attachment.url + ')').removeClass('cwp-thumb-empty');
          }
          self.updateSocialPreviewImage(target, attachment.url);
          self.validateImageDimensions(attachment.id, target);
        });

        frame.open();
      });

      this.$mb.find('.cwp-img-remove-btn').on('click', function(e) {
        e.preventDefault();
        var $btn = $(this);
        var target  = $btn.data('target');
        var $inputEl = $('#' + target);
        var $thumbEl = $btn.closest('.cwp-img-picker').find('.cwp-img-thumb');
        $inputEl.val('');
        if ($thumbEl.length) {
          var fi = crawlwpSEO.featuredImageUrl || '';
          if (fi) {
            $thumbEl.css('backgroundImage', 'url(' + fi + ')').removeClass('cwp-thumb-empty');
          } else {
            $thumbEl.css('backgroundImage', '').addClass('cwp-thumb-empty');
          }
        }
        self.updateSocialPreviewImage(target, '');
        /* Hide dimension validation when image removed */
        var dimId = target === 'cwpOgImage' ? 'cwpOgImgDimensions' : 'cwpXImgDimensions';
        $('#' + dimId).prop('hidden', true);
      });
    },

    updateSocialPreviewImage: function(targetId, imageUrl) {
      if (targetId === 'cwpOgImage') {
        var $fbCard = this.$mb.find('.cwp-fb-card .cwp-og-img');
        if ($fbCard.length) {
          if (imageUrl) {
            $fbCard.css('backgroundImage', 'url(' + imageUrl + ')').text('');
          } else {
            var fiFb = crawlwpSEO.featuredImageUrl || '';
            if (fiFb) {
              $fbCard.css('backgroundImage', 'url(' + fiFb + ')').text('');
            } else {
              $fbCard.css('backgroundImage', '').text('1200 \u00d7 630');
            }
          }
        }
        /* Also update X preview if X has no custom image */
        var $xInput = $('#cwpXImage');
        if ($xInput.length && !$xInput.val()) {
          var $xCard = this.$mb.find('.cwp-x-card .cwp-og-img');
          if ($xCard.length) {
            if (imageUrl) {
              $xCard.css('backgroundImage', 'url(' + imageUrl + ')').text('');
            } else {
              var fiX1 = crawlwpSEO.featuredImageUrl || '';
              if (fiX1) {
                $xCard.css('backgroundImage', 'url(' + fiX1 + ')').text('');
              } else {
                $xCard.css('backgroundImage', '').text('1200 \u00d7 675');
              }
            }
          }
        }
      } else if (targetId === 'cwpXImage') {
        var $xCard2 = this.$mb.find('.cwp-x-card .cwp-og-img');
        if ($xCard2.length) {
          if (imageUrl) {
            $xCard2.css('backgroundImage', 'url(' + imageUrl + ')').text('');
          } else {
            var $ogInput = $('#cwpOgImage');
            var $ogThumb = $ogInput.length ? $ogInput.closest('.cwp-img-picker').find('.cwp-img-thumb') : $();
            var fallback = $ogThumb.length ? $ogThumb.css('backgroundImage') : '';
            $xCard2.css('backgroundImage', fallback || '');
            if (fallback && fallback !== 'none') {
              $xCard2.text('');
            } else {
              var fiX2 = crawlwpSEO.featuredImageUrl || '';
              if (fiX2) {
                $xCard2.css('backgroundImage', 'url(' + fiX2 + ')').text('');
              } else {
                $xCard2.text('1200 \u00d7 675');
              }
            }
          }
        }
      }
    },

    /* ---------- featured image for social placeholders ---------- */
    initSocialImagePlaceholders: function() {
      var featuredUrl = crawlwpSEO.featuredImageUrl || '';
      this.$mb.find('.cwp-img-picker').each(function() {
        var $picker = $(this);
        var $thumb = $picker.find('.cwp-img-thumb');
        var $input = $picker.find('input[type="hidden"]');
        if (!$thumb.length || !$input.length) return;
        if ($input.val()) return;
        if (featuredUrl) {
          $thumb.css('backgroundImage', 'url(' + featuredUrl + ')').removeClass('cwp-thumb-empty');
        } else {
          $thumb.css('backgroundImage', '').addClass('cwp-thumb-empty');
        }
      });
    },

    watchFeaturedImage: function() {
      var self = this;
      if (typeof wp !== 'undefined' && wp.data && wp.data.subscribe && wp.data.select('core/editor')) {
        var _lastFeaturedId = null;
        wp.data.subscribe(function() {
          var editor = wp.data.select('core/editor');
          if (!editor) return;
          var fid = editor.getEditedPostAttribute('featured_media');
          if (fid === _lastFeaturedId) return;
          _lastFeaturedId = fid;
          if (fid) {
            var media = wp.data.select('core').getMedia(fid);
            if (media && media.source_url) {
              crawlwpSEO.featuredImageUrl = media.source_url;
            }
          } else {
            crawlwpSEO.featuredImageUrl = '';
          }
          self.initSocialImagePlaceholders();
        });
      }
    },

    /* ---------- helpers ---------- */
    /* simple sprintf: replaces %s, %d, %1$s, %2$s … with positional args */
    fmt: function(str) {
      var args = Array.prototype.slice.call(arguments, 1);
      var i = 0;
      return str.replace(/%(?:(\d+)\$)?[sd]/g, function(m, num) {
        if (num) { var idx = parseInt(num, 10) - 1; return args[idx] !== undefined ? args[idx] : ''; }
        return args[i] !== undefined ? args[i++] : '';
      });
    },

    escHtml: function(str) {
      return $('<div>').text(str).html();
    },

    /* Escape a string for use inside a double- or single-quoted HTML attribute. */
    escAttr: function(str) {
      return String(str == null ? '' : str)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
    },

    decodeEntities: function(str) {
      if (!str) return '';
      var txt = document.createElement('textarea');
      txt.innerHTML = str;
      return txt.value;
    },

    /* Only allow http(s) URLs or a single-slash relative path in href — blocks javascript:, data:, //host etc. */
    safeUrl: function(url) {
      var u = $.trim(String(url == null ? '' : url));
      if (/^https?:\/\//i.test(u)) return u;
      if (u.charAt(0) === '/' && u.charAt(1) !== '/') return u;
      return '';
    },

    stripTags: function(html) {
      return $('<div>').html(html).text();
    },

    getWordCount: function(text) {
      return text.split(/\s+/).filter(function(w) { return w.length > 0; }).length;
    },

    getEditorContent: function() {
      /* Try TinyMCE first, then Gutenberg data store, then DOM fallbacks */
      if (typeof tinymce !== 'undefined') {
        var ed = tinymce.get('content');
        /* ed.initialized only becomes true once TinyMCE has finished loading
           the post content into its iframe. Calling getContent() before that
           (e.g. during this metabox's very first init() run) can return an
           empty string even though the post already has real content -- that
           used to make Analysis/Readability wrongly report "no content" until
           the user typed something inside the editor. Fall through to the
           plain textarea below until TinyMCE is actually ready. */
        if (ed && !ed.isHidden() && ed.initialized) return ed.getContent();
      }
      /* Gutenberg: the block editor canvas is iframed, so DOM scraping fails.
         Use the data store which returns clean serialized post content. */
      if (typeof wp !== 'undefined' && wp.data && wp.data.select('core/editor')) {
        var sel = wp.data.select('core/editor');
        if (sel && typeof sel.getEditedPostContent === 'function') {
          var content = sel.getEditedPostContent();
          if (content) return content;
        }
        if (sel && typeof sel.getEditedPostAttribute === 'function') {
          var attr = sel.getEditedPostAttribute('content');
          if (typeof attr === 'string' && attr) return attr;
        }
      }
      var $wpBlock = $('.block-editor-block-list__layout');
      if ($wpBlock.length) return $wpBlock.html();
      /* Classic editor: the raw textarea holds the content while TinyMCE is
         still initialising, and is the only source in the "Text" tab.
         The content is never localized into crawlwpSEO — it would add
         hundreds of kilobytes to every editor page load. */
      var $ta = $('#content');
      if ($ta.length) return $ta.val() || '';
      if (typeof tinymce !== 'undefined') {
        var fallbackEd = tinymce.get('content');
        if (fallbackEd) return fallbackEd.getContent();
      }
      return '';
    },

    parseLinks: function(html) {
      var $tmp = $('<div>').html(html);
      var siteHost = new URL(crawlwpSEO.siteUrl).hostname;
      var internal = [], external = [];
      $tmp.find('a[href]').each(function() {
        var $a = $(this);
        var href = $a.attr('href');
        if (!href || href.charAt(0) === '#') return;
        var text = $.trim($a.text()) || href;
        try {
          var url = new URL(href, crawlwpSEO.siteUrl);
          var item = { href: url.href, text: text };
          if (url.hostname === siteHost) {
            internal.push(item);
          } else {
            external.push(item);
          }
        } catch(e) {
          external.push({ href: href, text: text });
        }
      });
      return { internal: internal, external: external };
    },

    /* ---------- show-all toggle helpers ---------- */
    addShowAll: function($container, total) {
      var $btn = $('<a>', {
        href: '#',
        'class': 'cwp-show-all-link',
        text: this.fmt(crawlwpSEO.i18n.showAllLinks, total)
      }).on('click', function(e) {
        e.preventDefault();
        $container.find('.cwp-link-hidden').removeClass('cwp-link-hidden');
        $btn.remove();
      });
      $container.append($btn);
    },

    removeShowAll: function($container) {
      $container.find('.cwp-show-all-link').remove();
    },

    /* ---------- links panel ---------- */
    renderLinks: function() {
      var self = this;
      var html = this.getEditorContent();
      var parsed = this.parseLinks(html);
      var outTotal = parsed.internal.length + parsed.external.length;
      var inbound = crawlwpSEO.inboundLinks || [];

      $('#cwpLinksOut').text(outTotal);
      $('#cwpLinksIn').text(inbound.length);
      $('#cwpLinksExt').text(parsed.external.length);

      /* notice */
      var $notice = $('#cwpLinksNotice');
      var $noticeText = $('#cwpLinksNoticeText');
      var $linksDot = this.$mb.find('.cwp-dot-links');
      if (parsed.internal.length === 0) {
        $notice.prop('hidden', false);
        $noticeText.text(crawlwpSEO.i18n.noInternalLinks);
        $linksDot.prop('hidden', false);
      } else {
        $notice.prop('hidden', true);
        $linksDot.prop('hidden', true);
      }

      /* outbound list */
      var $outList = $('#cwpOutboundLinks');
      var $outEmpty = $('#cwpOutboundEmpty');
      $outList.find('.cwp-link-item').remove();
      this.removeShowAll($outList);
      if (outTotal === 0) {
        $outEmpty.prop('hidden', false);
      } else {
        $outEmpty.prop('hidden', true);
        var all = parsed.internal.map(function(l) { l.type = 'internal'; return l; })
          .concat(parsed.external.map(function(l) { l.type = 'external'; return l; }));
        $.each(all, function(idx, link) {
          var chipClass = link.type === 'internal' ? 'is-good' : 'is-muted';
          var chipLabel = link.type === 'internal' ? crawlwpSEO.i18n.internal : crawlwpSEO.i18n.external;
          var $div = $('<div>', { 'class': 'cwp-link-item' });
          if (idx >= 6) $div.addClass('cwp-link-hidden');
          $div.html('<div class="cwp-link-main">' +
            '<a class="cwp-link-title" href="' + self.escAttr(self.safeUrl(link.href)) + '" target="_blank" rel="noopener noreferrer">' + self.escHtml(link.text) + '</a>' +
            '<div class="cwp-link-meta">' + self.escHtml(link.href) + '</div>' +
            '</div>' +
            '<div class="cwp-link-side"><span class="cwp-chip ' + chipClass + '">' + chipLabel + '</span></div>');
          $outList.append($div);
        });
        if (all.length > 6) this.addShowAll($outList, all.length);
      }

      /* inbound list */
      var $inList = $('#cwpInboundLinks');
      var $inEmpty = $('#cwpInboundEmpty');
      $inList.find('.cwp-link-item').remove();
      this.removeShowAll($inList);
      if (inbound.length === 0) {
        $inEmpty.prop('hidden', false);
      } else {
        $inEmpty.prop('hidden', true);
        $.each(inbound, function(idx, link) {
          var $div = $('<div>', { 'class': 'cwp-link-item is-inbound' });
          if (idx >= 6) $div.addClass('cwp-link-hidden');
          var anchorInfo = link.anchor ? self.fmt(crawlwpSEO.i18n.anchorLabel, self.escHtml(link.anchor)) + ' \u00b7 ' : '';
          $div.html('<div class="cwp-link-main">' +
            '<a class="cwp-link-title" href="' + self.escAttr(self.safeUrl(link.url)) + '" target="_blank" rel="noopener noreferrer">' + self.escHtml(link.title) + '</a>' +
            '<div class="cwp-link-meta">' + anchorInfo + self.fmt(crawlwpSEO.i18n.publishedDate, self.escHtml(link.date)) + '</div>' +
            '</div>');
          $inList.append($div);
        });
        if (inbound.length > 6) this.addShowAll($inList, inbound.length);
      }

      /* suggested links */
      var suggested = crawlwpSEO.suggestedLinks || [];
      var $sugList = $('#cwpSuggestedLinks');
      var $sugEmpty = $('#cwpSuggestedEmpty');
      $sugList.find('.cwp-link-item').remove();
      this.removeShowAll($sugList);
      if (suggested.length === 0) {
        $sugEmpty.prop('hidden', false);
      } else {
        $sugEmpty.prop('hidden', true);
        $.each(suggested, function(idx, link) {
          var $div = $('<div>', { 'class': 'cwp-link-item is-suggested' });
          if (idx >= 6) $div.addClass('cwp-link-hidden');
          $div.html('<div class="cwp-link-main">' +
            '<a class="cwp-link-title" href="' + self.escAttr(self.safeUrl(link.url)) + '" target="_blank" rel="noopener noreferrer">' + self.escHtml(link.title) + '</a>' +
            '<div class="cwp-link-meta">' + self.escHtml(link.url) + ' \u00b7 ' + self.escHtml(link.date) + '</div>' +
            '</div>' +
            '<div class="cwp-link-side">' +
              '<button class="cwp-copy-url-btn" type="button" data-url="' + self.escAttr(self.safeUrl(link.url)) + '" title="' + self.escAttr(crawlwpSEO.i18n.copyUrl) + '"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg></button>' +
              '<span class="cwp-chip is-info">' + crawlwpSEO.i18n.suggested + '</span>' +
            '</div>');
          $sugList.append($div);
        });
        if (suggested.length > 6) this.addShowAll($sugList, suggested.length);

        /* bind copy buttons */
        $sugList.find('.cwp-copy-url-btn').on('click', function(e) {
          e.preventDefault();
          var $btn = $(this);
          var url = $btn.attr('data-url');
          if (navigator.clipboard) {
            navigator.clipboard.writeText(url).then(function() {
              $btn.addClass('is-copied').attr('title', crawlwpSEO.i18n.copied);
              setTimeout(function() { $btn.removeClass('is-copied').attr('title', crawlwpSEO.i18n.copyUrl); }, 1500);
            });
          } else {
            var $ta = $('<textarea>').val(url).css({ position: 'fixed', left: '-9999px' }).appendTo('body');
            $ta[0].select();
            document.execCommand('copy');
            $ta.remove();
            $btn.addClass('is-copied').attr('title', crawlwpSEO.i18n.copied);
            setTimeout(function() { $btn.removeClass('is-copied').attr('title', crawlwpSEO.i18n.copyUrl); }, 1500);
          }
        });
      }
    },

    /* Suggestions are keyword-driven, so refetch them whenever the focus
       keyword changes instead of waiting for a page reload. */
    _suggestReq: 0,

    refreshSuggestedLinks: function(keyword) {
      var self = this;

      if (!crawlwpSEO.postId || !crawlwpSEO.suggestedNonce) return;

      var token = ++this._suggestReq;

      $.ajax({
        url: crawlwpSEO.ajaxUrl,
        type: 'POST',
        data: {
          action: 'crawlwp_suggested_links',
          nonce: crawlwpSEO.suggestedNonce,
          keyword: $.trim(keyword || ''),
          post_id: crawlwpSEO.postId
        },
        success: function(resp) {
          /* Ignore responses that a newer request has already superseded. */
          if (token !== self._suggestReq) return;
          if (resp.success && resp.data && $.isArray(resp.data.links)) {
            crawlwpSEO.suggestedLinks = resp.data.links;
            self.emit('renderLinks');
          }
        }
      });
    },

    /* ---------- readability badge ---------- */
    updateReadability: function(plainText, wordCount, sentences) {
      var L = crawlwpSEO.i18n;
      var $badge = $('#cwpReadabilityBadge');
      var $icon  = $('#cwpReadabilityIcon');
      var $text  = $('#cwpReadabilityText');
      if (!$badge.length) return;

      if (wordCount < 50) {
        $badge.attr('class', 'cwp-readability-badge');
        $icon.text('\u2014');
        $text.text(L.readabilityNA);
        return;
      }

      /* Flesch Reading Ease (approximation) */
      var syllableCount = 0;
      var words = plainText.split(/\s+/).filter(function(w) { return w.length > 0; });
      $.each(words, function(i, w) {
        var m = w.replace(/(?:[^laeiouy]es|ed|[^laeiouy]e)$/i, '').replace(/^y/i, '').match(/[aeiouy]{1,2}/gi);
        syllableCount += Math.max(1, m ? m.length : 1);
      });
      var flesch = 206.835 - (1.015 * (wordCount / sentences.length)) - (84.6 * (syllableCount / wordCount));
      flesch = Math.max(0, Math.min(100, Math.round(flesch)));

      /* long sentences (>20 words) percentage */
      var longSentences = 0;
      $.each(sentences, function(i, s) {
        var sw = $.trim(s).split(/\s+/).filter(function(w) { return w.length > 0; }).length;
        if (sw > 20) longSentences++;
      });
      var longPct = Math.round(longSentences / sentences.length * 100);
      var avgLen = Math.round(wordCount / sentences.length);

      var label, cls;
      if (flesch >= 60) {
        label = L.readabilityGood; cls = 'is-good';
      } else if (flesch >= 40) {
        label = L.readabilityOk; cls = 'is-ok';
      } else {
        label = L.readabilityPoor; cls = 'is-poor';
      }

      $badge.attr('class', 'cwp-readability-badge ' + cls);
      $icon.text(flesch);
      $text.html('<b>' + label + '</b> \u2014 ' + this.fmt(L.readabilityDetail, flesch, avgLen, longPct));
    },

    /* ---------- breadcrumb preview ---------- */
    updateBreadcrumbPreview: function() {
      var $el = $('#cwpBreadcrumbPreview');
      if (!$el.length) return;
      var crumbs = crawlwpSEO.breadcrumbs || [];
      var label = $.trim($('#cwpBreadcrumb').val()) || crawlwpSEO.postTitle || '';
      var trail = crumbs.concat([label]);
      var html = '';
      for (var i = 0; i < trail.length; i++) {
        if (i > 0) html += ' <span class="cwp-bc-sep">\u203a</span> ';
        html += '<span class="cwp-bc-item' + (i === trail.length - 1 ? ' is-current' : '') + '">' + this.escHtml(this.decodeEntities(trail[i])) + '</span>';
      }
      $el.html(html);
    },

    /* ---------- focus keyword duplicate check ---------- */
    checkDuplicateKeyword: function() {
      var self = this;
      var raw = $.trim($('#cwpKeyword').val());
      var $warning = $('#cwpKwWarning');
      var $text = $('#cwpKwWarningText');
      var L = crawlwpSEO.i18n;

      if (!raw) {
        $warning.hide();
        return;
      }

      $.ajax({
        url: crawlwpSEO.ajaxUrl,
        type: 'POST',
        data: {
          action: 'crawlwp_check_duplicate_keyword',
          nonce: crawlwpSEO.kwCheckNonce,
          keyword: raw,
          post_id: crawlwpSEO.postId
        },
        success: function(resp) {
          if (resp.success && resp.data && resp.data.duplicate) {
            var msg;
            if (resp.data.keyword && L.kwDuplicateWarnWithKw) {
              msg = self.fmt(L.kwDuplicateWarnWithKw, '<b>"' + self.escHtml(resp.data.keyword) + '"</b>', '<b>' + self.escHtml(resp.data.postTitle) + '</b>');
            } else {
              msg = self.fmt(L.kwDuplicateWarn, '<b>' + self.escHtml(resp.data.postTitle) + '</b>');
            }
            $text.html(msg +
              (self.safeUrl(resp.data.editUrl) ? ' <a href="' + self.escAttr(self.safeUrl(resp.data.editUrl)) + '" target="_blank" rel="noopener noreferrer">\u2192 ' + self.escHtml(resp.data.postTitle) + '</a>' : ''));
            $warning.css('display', 'flex');
          } else {
            $warning.hide();
          }
        }
      });
    },

    /* ---------- social image dimension validation ---------- */
    validateImageDimensions: function(attachmentId, targetId) {
      if (!attachmentId) return;
      var self = this;
      var isOg = (targetId === 'cwpOgImage');
      var minW = isOg ? 1200 : 800;
      var minH = isOg ? 630 : 418;
      var $dim = $('#' + (isOg ? 'cwpOgImgDimensions' : 'cwpXImgDimensions'));
      if (!$dim.length) return;

      /* Use wp.media attachment model to get dimensions */
      if (typeof wp !== 'undefined' && wp.media && wp.media.attachment) {
        var att = wp.media.attachment(attachmentId);
        att.fetch().then(function() {
          var w = att.get('width') || 0;
          var h = att.get('height') || 0;
          self.showImageDimensionResult($dim, w, h, minW, minH);
        });
      }
    },

    showImageDimensionResult: function($dim, w, h, minW, minH) {
      var L = crawlwpSEO.i18n;
      if (!w || !h) { $dim.prop('hidden', true); return; }
      var sizeStr = this.fmt(L.imgDimensions, w, h);
      if (w < minW || h < minH) {
        var minStr = (minW === 1200) ? L.imgOgMin : L.imgXMin;
        $dim.html('<span class="cwp-img-dim-warn">\u26a0 ' + sizeStr + ' \u2014 ' + L.imgTooSmall + ' ' + minStr + '</span>').prop('hidden', false);
      } else {
        $dim.html('<span class="cwp-img-dim-good">\u2713 ' + sizeStr + ' \u2014 ' + L.imgSizeGood + '</span>').prop('hidden', false);
      }
    },

    validateExistingImages: function() {
      var ogVal = $('#cwpOgImage').val();
      var xVal = $('#cwpXImage').val();
      if (ogVal) this.validateImageDimensions(parseInt(ogVal, 10), 'cwpOgImage');
      if (xVal) this.validateImageDimensions(parseInt(xVal, 10), 'cwpXImage');
    },

    /* ---------- analysis panel ---------- */
    runAnalysis: function() {
      var self = this;
      var rawKeywords = $.trim($('#cwpKeyword').val());
      var keywords = rawKeywords.split(',').map(function(k) {
        return $.trim(k);
      }).filter(Boolean);

      var $checklist = $('#cwpChecklist');
      var $noticeText = $('#cwpAnalysisNoticeText');
      var $analysisDot = this.$mb.find('.cwp-dot-analysis');
      var $kwTabs = $('#cwpKwTabs');
      var L = crawlwpSEO.i18n;

      /* always update readability badge regardless of keyword */
      var html = this.getEditorContent();
      var plainText = this.stripTags(html).toLowerCase();
      var wordCount = this.getWordCount(plainText);
      var sentences = plainText.split(/[.!?]+/).filter(function(s) { return $.trim(s).length > 5; });
      this.updateReadability(plainText, wordCount, sentences);

      if (keywords.length === 0) {
        $checklist.empty();
        $kwTabs.hide().empty();
        this.activeKwIndex = 0;
        this.analysisResults = [];
        $noticeText.text(L.enterFocusKw);
        $analysisDot.prop('hidden', true);
        this.updateScore(0, 0);
        return;
      }

      var seoTitle = this.resolve(this.$title.val() || '{{ post.title }} {{ sep }} {{ site.title }}').toLowerCase();
      var rawTitleVal = this.$title.val() || '{{ post.title }} {{ sep }} {{ site.title }}';
      var titleText = this.resolve(rawTitleVal);
      var titlePx = this.widthOf(titleText, 'bold 20px Arial');
      var seoDesc = this.resolve(this.$desc.val() || '').toLowerCase();
      var descPx = this.widthOf(this.resolve(this.$desc.val()), '14px Arial');
      var slugVal = (this.getSlug() || '').toLowerCase();
      var parsed = this.parseLinks(html);

      var $tmp = $('<div>').html(html);
      var $headings = $tmp.find('h1,h2,h3,h4,h5,h6');
      var $h2s = $tmp.find('h2');

      var $images = $tmp.find('img');
      var imagesNoAlt = 0;
      $images.each(function() {
        var alt = $.trim($(this).attr('alt') || '');
        if (!alt) imagesNoAlt++;
      });

      var $paragraphs = $tmp.find('p');
      var firstParaText = $paragraphs.length > 0 ? ($paragraphs.first().text() || '').toLowerCase() : '';
      var avgSentenceLen = sentences.length > 0 ? Math.round(wordCount / sentences.length) : 0;

      var sharedData = {
        plainText: plainText,
        wordCount: wordCount,
        sentences: sentences,
        avgSentenceLen: avgSentenceLen,
        seoTitle: seoTitle,
        titlePx: titlePx,
        seoDesc: seoDesc,
        descPx: descPx,
        slugVal: slugVal,
        parsed: parsed,
        $headings: $headings,
        $h2s: $h2s,
        $images: $images,
        imagesNoAlt: imagesNoAlt,
        firstParaText: firstParaText
      };

      var results = [];
      for (var i = 0; i < keywords.length; i++) {
        var kw = keywords[i];
        var res = self.evaluateKeyword(kw, i === 0, sharedData);
        var score = res.total > 0 ? Math.round(res.passed / res.total * 100) : 0;
        results.push({
          keyword: kw,
          isPrimary: (i === 0),
          passed: res.passed,
          total: res.total,
          score: score,
          checks: res.checks
        });
      }

      this.analysisResults = results;

      if (this.activeKwIndex >= results.length) {
        this.activeKwIndex = 0;
      }

      var headlineScore = results[0].score;
      if (results.length > 1) {
        var secSum = 0;
        for (var s = 1; s < results.length; s++) {
          secSum += results[s].score;
        }
        var secAvg = secSum / (results.length - 1);
        headlineScore = Math.round((results[0].score * 0.7) + (secAvg * 0.3));
      }

      this.renderAnalysisTabs();
      this.updateScore(headlineScore, 100);
    },

    evaluateKeyword: function(kw, isPrimary, d) {
      var self = this;
      var L = crawlwpSEO.i18n;
      var kwLower = kw.toLowerCase();
      var checks = [];
      var passed = 0;
      var total = 0;

      function addCheck(status, boldText, detail) {
        total++;
        if (status === 'good') passed++;
        checks.push({ status: status, bold: boldText, detail: detail });
      }

      var keywordInAlt = false;
      d.$images.each(function() {
        if (($(this).attr('alt') || '').toLowerCase().indexOf(kwLower) !== -1) keywordInAlt = true;
      });

      var headingsWithKw = 0;
      d.$headings.each(function() {
        if ($(this).text().toLowerCase().indexOf(kwLower) !== -1) headingsWithKw++;
      });

      var kwCount = 0;
      if (kwLower && d.plainText) {
        var re = new RegExp(kwLower.replace(/[.*+?^${}()|[\]\\]/g, '\\$&'), 'gi');
        var matches = d.plainText.match(re);
        kwCount = matches ? matches.length : 0;
      }
      var density = d.wordCount > 0 ? (kwCount / d.wordCount * 100) : 0;

      if (isPrimary) {
        /* 1. Keyword in SEO title */
        if (d.seoTitle.indexOf(kwLower) !== -1) {
          var pos = d.seoTitle.indexOf(kwLower);
          if (pos < d.seoTitle.length / 3) {
            addCheck('good', L.kwInTitleGood, L.kwInTitleStart);
          } else {
            addCheck('good', L.kwInTitleGood, L.kwInTitleMove);
          }
        } else {
          addCheck('bad', L.kwInTitleBad, L.kwInTitleFix);
        }

        /* 2. Keyword in URL slug */
        if (d.slugVal.indexOf(kwLower.replace(/\s+/g, '-')) !== -1 || d.slugVal.indexOf(kwLower.replace(/\s+/g, '')) !== -1) {
          addCheck('good', L.kwInSlugGood, '');
        } else {
          addCheck('bad', L.kwInSlugBad, L.kwInSlugFix);
        }
      } else {
        /* Secondary: In Content Body */
        if (kwCount > 0) {
          addCheck('good', L.kwInContentGood, self.fmt(L.kwInContentGoodD, kwCount));
        } else {
          addCheck('bad', L.kwInContentBad, L.kwInContentBadD);
        }
      }

      /* 3. Title length (pixel width) */
      if (d.titlePx >= 200 && d.titlePx <= 580) {
        addCheck('good', L.titleLenGood, this.fmt(L.titleLenDetail, d.titlePx));
      } else if (d.titlePx > 580) {
        addCheck('warn', L.titleLenLong, this.fmt(L.titleLenLongD, d.titlePx));
      } else {
        addCheck('warn', L.titleLenShort, this.fmt(L.titleLenShortD, d.titlePx));
      }

      /* 4. Meta description */
      if (d.seoDesc.length > 0) {
        if (d.seoDesc.indexOf(kwLower) !== -1) {
          addCheck('good', L.kwInDescGood, '');
        } else {
          addCheck('warn', L.kwInDescWarn, L.kwInDescWarnD);
        }
      } else {
        addCheck('bad', L.noDescBad, L.noDescFix);
      }

      /* 5. Meta description length */
      if (d.seoDesc.length > 0) {
        if (d.descPx >= 400 && d.descPx <= 920) {
          addCheck('good', L.descLenGood, this.fmt(L.descLenGoodD, d.descPx));
        } else if (d.descPx > 920) {
          addCheck('warn', L.descLenLong, this.fmt(L.descLenLongD, d.descPx));
        } else {
          addCheck('warn', L.descLenShort, L.descLenShortD);
        }
      }

      /* 6. Keyword in first paragraph */
      if (d.firstParaText && d.firstParaText.indexOf(kwLower) !== -1) {
        addCheck('good', L.kwFirstParaGood, '');
      } else if (d.plainText.length > 0) {
        addCheck('warn', L.kwFirstParaWarn, L.kwFirstParaFix);
      }

      /* 7. Keyword in subheadings */
      if (headingsWithKw >= 2) {
        addCheck('good', this.fmt(L.kwSubheadGood, headingsWithKw), '');
      } else if (headingsWithKw === 1) {
        addCheck('warn', L.kwSubheadOne, L.kwSubheadOneFix);
      } else if (d.$headings.length > 0) {
        addCheck(isPrimary ? 'bad' : 'warn', L.kwSubheadBad, L.kwSubheadFix);
      }

      /* 8. Images alt text */
      if (d.$images.length === 0) {
        addCheck('warn', L.noImages, L.noImagesFix);
      } else if (d.imagesNoAlt === 0) {
        addCheck('good', L.allImgAlt, this.fmt(L.imgAltDetail, d.$images.length));
      } else {
        addCheck('bad', this.fmt(L.imgAltMissing, d.imagesNoAlt), L.imgAltFix);
      }

      /* 9. Keyword in image alt */
      if (d.$images.length > 0) {
        if (keywordInAlt) {
          addCheck('good', L.kwImgAltGood, '');
        } else {
          addCheck('warn', L.kwImgAltWarn, L.kwImgAltFix);
        }
      }

      /* 10. Internal links */
      if (d.parsed.internal.length >= 2) {
        addCheck('good', this.fmt(L.intLinksGood, d.parsed.internal.length), L.intLinksGoodD);
      } else if (d.parsed.internal.length === 1) {
        addCheck('warn', L.intLinksOne, L.intLinksOneFix);
      } else {
        addCheck('bad', L.intLinksNone, L.intLinksNoneFix);
      }

      /* 11. External links */
      if (d.parsed.external.length >= 1) {
        addCheck('good', this.fmt(L.extLinksGood, d.parsed.external.length), L.extLinksGoodD);
      } else {
        addCheck('warn', L.extLinksNone, L.extLinksNoneFix);
      }

      /* 12. Content length */
      if (d.wordCount >= 300) {
        addCheck('good', this.fmt(L.wordsLabel, d.wordCount.toLocaleString()), L.wordsEnough);
      } else if (d.wordCount >= 100) {
        addCheck('warn', this.fmt(L.wordsLabel, d.wordCount.toLocaleString()), L.wordsAim300);
      } else {
        addCheck('bad', this.fmt(L.wordsLabel, d.wordCount.toLocaleString()), L.wordsThin);
      }

      /* 13. Keyword density */
      if (d.wordCount > 50) {
        if (density >= 0.5 && density <= 3.0) {
          addCheck('good', this.fmt(L.densityLabel, density.toFixed(1)), L.densityGoodD);
        } else if (density > 3.0) {
          addCheck('warn', this.fmt(L.densityLabel, density.toFixed(1)), L.densityHighD);
        } else {
          addCheck('warn', this.fmt(L.densityLabel, density.toFixed(1)), L.densityLowD);
        }
      }

      /* 14. Readability: avg sentence length */
      if (d.sentences.length >= 3) {
        if (d.avgSentenceLen <= 20) {
          addCheck('good', this.fmt(L.readability, d.avgSentenceLen), L.readabilityGoodD);
        } else if (d.avgSentenceLen <= 25) {
          addCheck('warn', this.fmt(L.readability, d.avgSentenceLen), L.readabilityWarnD);
        } else {
          addCheck('bad', this.fmt(L.readability, d.avgSentenceLen), L.readabilityBadD);
        }
      }

      /* 15. Heading hierarchy (uses H2s) */
      if (d.$h2s.length >= 2) {
        addCheck('good', this.fmt(L.h2Good, d.$h2s.length), '');
      } else if (d.$h2s.length === 1) {
        addCheck('warn', L.h2One, L.h2OneFix);
      } else if (d.wordCount > 300) {
        addCheck('warn', L.h2None, L.h2NoneFix);
      }

      return { passed: passed, total: total, checks: checks };
    },

    renderAnalysisTabs: function() {
      var self = this;
      var results = this.analysisResults || [];
      var $checklist = $('#cwpChecklist');
      var $noticeText = $('#cwpAnalysisNoticeText');
      var $analysisDot = this.$mb.find('.cwp-dot-analysis');
      var $kwTabs = $('#cwpKwTabs');
      var L = crawlwpSEO.i18n;

      if (!results.length) return;

      if (this.activeKwIndex >= results.length) {
        this.activeKwIndex = 0;
      }

      /* Render tabs if more than 1 keyword */
      if (results.length > 1) {
        $kwTabs.empty().show();
        $.each(results, function(idx, item) {
          var badgeCls = item.score >= 70 ? 'is-good' : (item.score >= 40 ? 'is-warn' : 'is-bad');
          var activeCls = (idx === self.activeKwIndex) ? ' is-active' : '';
          var roleText = item.isPrimary ? (L.primaryKw || 'Primary') : (L.secondaryKw || 'Secondary');

          var $tab = $('<button>', {
            type: 'button',
            'class': 'cwp-kw-tab' + activeCls,
            'data-kw-index': idx
          }).html(
            '<span class="cwp-kw-tab-role">' + self.escHtml(roleText) + '</span>' +
            '<span class="cwp-kw-tab-name">' + self.escHtml(item.keyword) + '</span>' +
            '<span class="cwp-kw-tab-badge ' + badgeCls + '">' + item.score + '%</span>'
          );

          $kwTabs.append($tab);
        });
      } else {
        $kwTabs.hide().empty();
      }

      var active = results[this.activeKwIndex];

      /* Notice text */
      if (active.isPrimary) {
        $noticeText.html(this.fmt(L.scoredAgainst, '<b>' + this.escHtml(active.keyword) + '</b>'));
      } else {
        var secTpl = L.scoredAgainstSecondary || 'Scored against secondary keyword %s. Title and slug checks are relaxed to prevent keyword stuffing.';
        $noticeText.html(this.fmt(secTpl, '<b>' + this.escHtml(active.keyword) + '</b>'));
      }

      /* Checklist items */
      $checklist.empty();
      var symbols = { good: '\u2713', warn: '!', bad: '\u2715' };
      $.each(active.checks, function(i, c) {
        var $div = $('<div>', { 'class': 'cwp-checkitem' });
        $div.html('<span class="cwp-badge is-' + c.status + '">' + symbols[c.status] + '</span>' +
          '<span class="cwp-checktext"><b>' + self.escHtml(c.bold) + '</b>' +
          (c.detail ? ' ' + self.escHtml(c.detail) : '') + '</span>');
        $checklist.append($div);
      });

      /* Dot counter */
      var issues = active.total - active.passed;
      if ($analysisDot.length) {
        if (issues > 0) {
          $analysisDot.prop('hidden', false).attr('title', this.fmt(L.issueCount, issues));
        } else {
          $analysisDot.prop('hidden', true);
        }
      }
    },

    updateScore: function(passed, total) {
      var pct = total > 0 ? Math.round(passed / total * 100) : 0;
      var $wrap = this.$mb.closest('#crawlwp-seo-metabox');
      if (!$wrap.length) $wrap = this.$mb;
      var $ring = $wrap.find('.cwp-fill');
      var $num = $wrap.find('.cwp-score-num');
      if ($ring.length) $ring.attr('stroke-dasharray', pct + ' 100');
      if ($num.length) $num.text(total > 0 ? pct : '\u2014');

      /* Persist the JS-calculated score so it is submitted with the post form.
       * Only write when analysis actually ran (total > 0).  When total === 0 the
       * analysis hasn't executed yet and the hidden input already holds the
       * previously stored score (pre-populated by PHP), so we leave it alone. */
      var $cache = $('#cwpSeoScoreCache');
      if ($cache.length && total > 0) {
        $cache.val(pct);
      }
    }

  };

  window.CrawlWPMetabox = CrawlWP;

  $(function() { CrawlWP.init(); });

})(jQuery);
