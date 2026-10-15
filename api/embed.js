/**
 * 院内かわら版 - 外部Webページ埋め込み用スクリプト
 * File: api/embed.js
 * 
 * 使い方:
 * <div id="kawara-widget"></div>
 * <script src="http://[サーバー]/kawara/api/embed.js" 
 *         data-container="#kawara-widget"
 *         data-limit="5"
 *         data-filter="active"
 *         data-theme="light"></script>
 */

(function() {
    'use strict';

    // スクリプト自身のURLからベースAPIパスを特定
    var currentScript = document.currentScript || (function() {
        var scripts = document.getElementsByTagName('script');
        return scripts[scripts.length - 1];
    })();

    var scriptSrc = currentScript ? currentScript.src : '';
    var baseApiUrl = scriptSrc.replace(/\/embed\.js(\?.*)?$/, '');

    // オプションの抽出
    var containerSelector = currentScript.getAttribute('data-container') || '#kawara-widget';
    var limit = parseInt(currentScript.getAttribute('data-limit') || '5', 10);
    var filter = currentScript.getAttribute('data-filter') || 'active';
    var category = currentScript.getAttribute('data-category') || '';
    var theme = currentScript.getAttribute('data-theme') || 'light';
    var showHeader = currentScript.getAttribute('data-header') !== 'false';
    var target = currentScript.getAttribute('data-target') || '_blank';

    // グローバルオブジェクト定義
    window.KawaraAPI = window.KawaraAPI || {
        baseUrl: baseApiUrl,
        fetchPosts: function(options) {
            options = options || {};
            var params = new URLSearchParams();
            if (options.limit) params.set('limit', options.limit);
            if (options.page) params.set('page', options.page);
            if (options.filter) params.set('filter', options.filter);
            if (options.category) params.set('category', options.category);
            if (options.q) params.set('q', options.q);
            if (options.staff_id) params.set('staff_id', options.staff_id);

            var url = (this.baseUrl || baseApiUrl) + '/posts.php?' + params.toString();
            return fetch(url, { credentials: 'omit' }).then(function(res) {
                if (!res.ok) throw new Error('Kawara API HTTP ' + res.status);
                return res.json();
            });
        },
        render: function(targetEl, options) {
            renderWidget(targetEl, options);
        }
    };

    function escapeHtml(str) {
        if (!str) return '';
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function injectStyles() {
        if (document.getElementById('kawara-embed-styles')) return;
        var style = document.createElement('style');
        style.id = 'kawara-embed-styles';
        style.textContent = [
            '.kawara-embed-root { font-family: "Noto Sans JP", -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; font-size: 13px; line-height: 1.5; color: #1e293b; }',
            '.kawara-embed-root.theme-dark { color: #f8fafc; }',
            '.kawara-embed-header { display: flex; align-items: center; justify-content: space-between; padding-bottom: 8px; margin-bottom: 8px; border-bottom: 2px solid #005a9c; }',
            '.kawara-embed-title { font-size: 14px; font-weight: 800; color: #005a9c; text-decoration: none; display: flex; align-items: center; gap: 6px; }',
            '.kawara-embed-more { font-size: 11px; color: #64748b; text-decoration: none; }',
            '.kawara-embed-more:hover { color: #005a9c; text-decoration: underline; }',
            '.kawara-embed-list { display: flex; flex-direction: column; gap: 6px; }',
            '.kawara-embed-item { display: block; background: #ffffff; border: 1px solid #e2e8f0; border-radius: 6px; padding: 8px 10px; text-decoration: none; color: inherit; transition: transform 0.15s, box-shadow 0.15s; }',
            '.kawara-embed-root.theme-dark .kawara-embed-item { background: #1e293b; border-color: #334155; }',
            '.kawara-embed-item:hover { transform: translateY(-1px); box-shadow: 0 3px 8px rgba(0,0,0,0.06); border-color: #94a3b8; }',
            '.kawara-embed-item.pinned { border-left: 4px solid #ef4444; background: #fff8f8; }',
            '.kawara-embed-root.theme-dark .kawara-embed-item.pinned { background: #2b1d20; }',
            '.kawara-embed-meta { display: flex; align-items: center; gap: 6px; margin-bottom: 4px; font-size: 11px; }',
            '.kawara-embed-cat { display: inline-flex; align-items: center; gap: 3px; font-size: 10px; font-weight: 700; padding: 1px 6px; border-radius: 4px; color: #fff; }',
            '.kawara-embed-pin { background: #ef4444; color: #fff; font-size: 10px; font-weight: bold; padding: 1px 5px; border-radius: 3px; }',
            '.kawara-embed-date { margin-left: auto; color: #64748b; font-size: 11px; }',
            '.kawara-embed-item-title { font-size: 13px; font-weight: 700; margin-bottom: 3px; }',
            '.kawara-embed-item-summary { font-size: 11.5px; color: #64748b; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; }',
            '.kawara-embed-loading { text-align: center; padding: 20px; color: #64748b; font-size: 12px; }',
            '.kawara-embed-empty { text-align: center; padding: 20px; color: #64748b; font-size: 12px; }'
        ].join('\n');
        document.head.appendChild(style);
    }

    function renderWidget(container, opts) {
        if (typeof container === 'string') {
            container = document.querySelector(container);
        }
        if (!container) return;

        opts = opts || {};
        var l = opts.limit || limit;
        var f = opts.filter || filter;
        var c = opts.category || category;
        var th = opts.theme || theme;
        var sh = typeof opts.showHeader !== 'undefined' ? opts.showHeader : showHeader;
        var tg = opts.target || target;

        injectStyles();

        container.innerHTML = '<div class="kawara-embed-root theme-' + escapeHtml(th) + '"><div class="kawara-embed-loading">⏳ かわら版のデータを読み込み中...</div></div>';

        window.KawaraAPI.fetchPosts({
            limit: l,
            filter: f,
            category: c
        }).then(function(res) {
            if (!res || !res.success || !res.posts) {
                container.innerHTML = '<div class="kawara-embed-root"><div class="kawara-embed-empty">データ取得に失敗しました</div></div>';
                return;
            }

            var posts = res.posts;
            var html = '<div class="kawara-embed-root theme-' + escapeHtml(th) + '">';

            if (sh) {
                var portalUrl = (baseApiUrl.replace(/\/api$/, '') || '') + '/kawara_list.php';
                html += '<div class="kawara-embed-header">';
                html += '  <a href="' + escapeHtml(portalUrl) + '" target="' + escapeHtml(tg) + '" class="kawara-embed-title">';
                html += '    <span>📰</span> <span>院内かわら版</span>';
                html += '  </a>';
                html += '  <a href="' + escapeHtml(portalUrl) + '" target="' + escapeHtml(tg) + '" class="kawara-embed-more">一覧を見る &rarr;</a>';
                html += '</div>';
            }

            if (posts.length === 0) {
                html += '<div class="kawara-embed-empty">現在、表示対象のお知らせはありません。</div>';
            } else {
                html += '<div class="kawara-embed-list">';
                for (var i = 0; i < posts.length; i++) {
                    var p = posts[i];
                    var catColor = (p.category && p.category.color_code) ? p.category.color_code : '#005a9c';
                    var catEmoji = (p.category && p.category.icon_emoji) ? p.category.icon_emoji : '📄';
                    var catName = (p.category && p.category.name) ? p.category.name : 'お知らせ';
                    var dateStr = p.target_period_formatted || (p.created_at ? p.created_at.substring(5, 10).replace('-', '/') : '');

                    html += '<a href="' + escapeHtml(p.view_url) + '" target="' + escapeHtml(tg) + '" class="kawara-embed-item ' + (p.is_pinned ? 'pinned' : '') + '">';
                    html += '  <div class="kawara-embed-meta">';
                    if (p.is_pinned) {
                        html += '    <span class="kawara-embed-pin">📌 注目</span>';
                    }
                    html += '    <span class="kawara-embed-cat" style="background: ' + escapeHtml(catColor) + ';">' + escapeHtml(catEmoji) + ' ' + escapeHtml(catName) + '</span>';
                    html += '    <span class="kawara-embed-date">' + escapeHtml(dateStr) + '</span>';
                    html += '  </div>';
                    html += '  <div class="kawara-embed-item-title">' + escapeHtml(p.title) + '</div>';
                    if (p.summary) {
                        html += '  <div class="kawara-embed-item-summary">' + escapeHtml(p.summary) + '</div>';
                    }
                    html += '</a>';
                }
                html += '</div>';
            }

            html += '</div>';
            container.innerHTML = html;

        }).catch(function(err) {
            console.error('Kawara Embed Error:', err);
            container.innerHTML = '<div class="kawara-embed-root"><div class="kawara-embed-empty">⚠️ かわら版データの取得に失敗しました</div></div>';
        });
    }

    // 自動レンダリング (DOM構築後)
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function() {
            var el = document.querySelector(containerSelector);
            if (el) renderWidget(el);
        });
    } else {
        var el = document.querySelector(containerSelector);
        if (el) renderWidget(el);
    }

})();
