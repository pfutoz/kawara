<!-- 院内かわら版 - LINE Flex Message 通知・プレビュー ＆ テスト送信共通モーダルコンポーネント -->
<div id="modal-line-notify" class="modal-overlay" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(15,23,42,0.65); z-index:9999; justify-content:center; align-items:center; backdrop-filter:blur(3px);">
    <div class="modal-box" style="background:#ffffff; max-width:840px; width:95%; border-radius:14px; box-shadow:0 12px 36px rgba(0,0,0,0.25); overflow:hidden; display:flex; flex-direction:column; max-height:92vh; font-family:-apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;">
        
        <!-- ヘッダー -->
        <div style="background:linear-gradient(135deg, #005a9c, #003d6b); color:#ffffff; padding:14px 20px; display:flex; justify-content:space-between; align-items:center;">
            <div style="font-weight:bold; font-size:1.05rem; display:flex; align-items:center; gap:8px;">
                <span>💬</span>
                <span>LINE Flex Message 通知 ＆ 1タップ意思表示配信</span>
            </div>
            <button type="button" onclick="closeLineNotifyModal()" style="border:none; background:none; font-size:1.5rem; color:#ffffff; cursor:pointer; line-height:1; opacity:0.85;">&times;</button>
        </div>

        <!-- ボディ -->
        <div style="padding:18px 20px; overflow-y:auto; flex:1;">
            <div id="line-notify-loading" style="text-align:center; padding:40px; color:#64748b; font-size:0.95rem;">
                🔄 記事データおよびLINEカードプレビューを生成しています...
            </div>

            <div id="line-notify-content" style="display:none; grid-template-columns: 310px 1fr; gap:22px; align-items:start;">
                
                <!-- 左側：スマホLINE実寸大プレビュー -->
                <div style="background:#7089a8; border-radius:20px; padding:16px 12px; box-shadow:0 8px 24px rgba(0,0,0,0.18);">
                    <div style="text-align:center; font-size:0.75rem; color:#ffffff; font-weight:bold; margin-bottom:8px; opacity:0.95; letter-spacing:0.05em;">
                        📱 スマホLINE トーク画面プレビュー
                    </div>
                    
                    <!-- Flex Card 本体のモック -->
                    <div id="line-preview-card" style="background:#ffffff; border-radius:12px; overflow:hidden; box-shadow:0 3px 12px rgba(0,0,0,0.14);">
                        <!-- カードヘッダー -->
                        <div id="preview-header" style="background:#005a9c; color:#ffffff; padding:10px 12px; display:flex; justify-content:space-between; align-items:center;">
                            <span id="preview-cat" style="font-size:0.82rem; font-weight:bold;">📋 お知らせ</span>
                            <span style="font-size:0.68rem; opacity:0.85;">院内伝達</span>
                        </div>
                        
                        <!-- カードボディ -->
                        <div style="padding:14px; display:flex; flex-direction:column; gap:10px;">
                            <div id="preview-title" style="font-size:0.92rem; font-weight:bold; color:#0f172a; line-height:1.4;">
                                タイトル
                            </div>
                            <hr style="border:none; border-top:1px solid #e2e8f0; margin:0;">
                            
                            <div style="font-size:0.78rem; display:flex; flex-direction:column; gap:4px;">
                                <div style="display:flex; gap:6px;">
                                    <span style="color:#64748b; width:45px; flex-shrink:0;">🗓️ 日程</span>
                                    <span id="preview-date" style="color:#1e293b; font-weight:bold;">-</span>
                                </div>
                                <div style="display:flex; gap:6px;">
                                    <span style="color:#64748b; width:45px; flex-shrink:0;">👥 対象</span>
                                    <span id="preview-dept" style="color:#1e293b;">-</span>
                                </div>
                            </div>
                            
                            <!-- 概要・注意事項スロット（赤枠強調） -->
                            <div style="background:#fef2f2; border:1px solid #fecdd3; border-radius:6px; padding:8px 10px;">
                                <div style="font-size:0.68rem; font-weight:bold; color:#dc2626;">⚠️ 概要・注意事項</div>
                                <div id="preview-notice" style="font-size:0.76rem; font-weight:bold; color:#991b1b; margin-top:2px; line-height:1.4; word-break:break-all;">
                                    -
                                </div>
                            </div>
                        </div>
                        
                        <!-- フッター（アクションボタン群） -->
                        <div style="background:#f8fafc; padding:10px 14px 12px; display:flex; flex-direction:column; gap:6px; border-top:1px solid #f1f5f9;">
                            <div style="background:#16a34a; color:#ffffff; text-align:center; padding:7px; border-radius:4px; font-weight:bold; font-size:0.8rem; cursor:default;">
                                👍 了解しました
                            </div>
                            <div style="display:flex; gap:6px;">
                                <div style="flex:1; background:#e2e8f0; color:#334155; text-align:center; padding:5px; border-radius:4px; font-size:0.72rem; font-weight:500;">
                                    ❓ 質問・確認
                                </div>
                                <div style="flex:1; background:#e2e8f0; color:#334155; text-align:center; padding:5px; border-radius:4px; font-size:0.72rem; font-weight:500;">
                                    ⚠️ 不在・不可
                                </div>
                            </div>
                            <div style="text-align:center; font-size:0.7rem; color:#0284c7; padding-top:2px;">
                                📄 かわら版で詳細を開く
                            </div>
                        </div>
                    </div>
                </div>

                <!-- 右側：送信設定・調整フォーム -->
                <div style="display:flex; flex-direction:column; gap:16px;">
                    <div>
                        <div style="font-size:0.82rem; font-weight:bold; color:#475569; margin-bottom:4px;">📡 送信対象 ＆ LINE連携状況:</div>
                        <div style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:8px; padding:10px 14px; font-size:0.86rem; color:#1e293b;">
                            <div><b>対象部署:</b> <span id="line-notify-depts">-</span></div>
                            <div style="margin-top:4px;">
                                <b>LINE連携済み:</b> <span id="line-notify-linked-count" style="font-weight:bold; color:#16a34a; font-size:1.1rem;">0</span>名
                                <span style="font-size:0.8rem; color:#64748b;">(対象 <span id="line-notify-total-count">0</span>名中)</span>
                            </div>
                        </div>
                    </div>

                    <div>
                        <label style="font-size:0.82rem; font-weight:bold; color:#475569; display:block; margin-bottom:4px;">
                            ✏️ LINE用ひとこと要約・注意事項（リアルタイム反映）:
                        </label>
                        <textarea id="line-notify-custom-notice" rows="3" style="width:100%; box-sizing:border-box; padding:8px 10px; border:1.5px solid #cbd5e1; border-radius:6px; font-size:0.86rem; line-height:1.4;" placeholder="例: 工事中、使用禁止となります。西側トイレをご利用ください。" oninput="updateLineModalPreview()"></textarea>
                        <span style="font-size:0.75rem; color:#64748b;">※カード中央の赤枠スロットに太字で表示されます（30〜60文字程度が最も読みやすいです）。</span>
                    </div>

                    <!-- 自分自身のLINE状態 -->
                    <div id="my-line-status-box" style="background:#eff6ff; border:1px solid #bfdbfe; border-radius:6px; padding:10px 12px; font-size:0.82rem; color:#1e40af; display:flex; align-items:center; justify-content:space-between;">
                        <span>📱 あなた（<b id="my-line-staff-name">-</b>）のLINE連携: <span id="my-line-badge" style="font-weight:bold; color:#16a34a;">連携済み</span></span>
                        <a href="safety_contacts.php" target="_blank" style="color:#0284c7; text-decoration:underline; font-size:0.78rem;">連携確認・変更</a>
                    </div>

                    <div style="background:#fefce8; border:1px solid #fef08a; border-radius:6px; padding:10px 12px; font-size:0.8rem; color:#854d0e; line-height:1.5;">
                        💡 <b>実務のおすすめ手順</b>:<br>
                        いきなり対象者全員に送る前に、まず下の「📲 まず自分のLINEにテスト送信」を押し、ご自身のスマホで届き方やボタンの動作を確認してから本番送信を行ってください。
                    </div>
                </div>

            </div>
        </div>

        <!-- フッター -->
        <div style="background:#f1f5f9; border-top:1px solid #e2e8f0; padding:12px 20px; display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px;">
            <!-- 左：自分宛テスト送信ボタン -->
            <button type="button" id="btn-line-test-me" onclick="executeLineTestMe()" style="background:#eff6ff; color:#1d4ed8; border:1.5px solid #60a5fa; font-weight:bold; padding:8px 16px; border-radius:6px; cursor:pointer; font-size:0.88rem; display:inline-flex; align-items:center; gap:6px;">
                📲 まず自分のLINEにテスト送信
            </button>

            <!-- 右：閉じる ＆ 本番送信ボタン群 -->
            <div style="display:flex; gap:8px;">
                <button type="button" onclick="closeLineNotifyModal()" style="background:#ffffff; border:1px solid #cbd5e1; color:#475569; padding:8px 16px; border-radius:6px; font-weight:bold; font-size:0.88rem; cursor:pointer;">
                    閉じる
                </button>
                <button type="button" id="btn-line-send-unread" onclick="executeLineBroadcast('unread_only')" style="background:#f59e0b; color:#ffffff; border:none; padding:8px 14px; border-radius:6px; font-weight:bold; font-size:0.88rem; cursor:pointer;">
                    ⚠️ 未読・未了解者のみへ送信
                </button>
                <button type="button" id="btn-line-send-all" onclick="executeLineBroadcast('all')" style="background:#16a34a; color:#ffffff; border:none; padding:8px 18px; border-radius:6px; font-weight:bold; font-size:0.88rem; cursor:pointer; box-shadow:0 2px 6px rgba(22,163,74,0.3);">
                    🚀 対象者全員へ本番送信
                </button>
            </div>
        </div>
    </div>
</div>

<script>
let currentLinePostId = 0;
let currentLineBubble = null;
let currentLinePostData = null;

function closeLineNotifyModal() {
    const m = document.getElementById('modal-line-notify');
    if (m) m.style.display = 'none';
}

async function openLineNotifyModal(postId, customNotice = '') {
    currentLinePostId = postId;
    const modal = document.getElementById('modal-line-notify');
    if (modal) modal.style.display = 'flex';

    const loading = document.getElementById('line-notify-loading');
    const content = document.getElementById('line-notify-content');
    if (loading) loading.style.display = 'block';
    if (content) content.style.display = 'none';

    try {
        const fd = new FormData();
        fd.append('action', 'get_preview');
        fd.append('post_id', postId);
        if (customNotice) fd.append('custom_notice', customNotice);

        const res = await fetch('api/line_post_notify_api.php', { method: 'POST', body: fd });
        const data = await res.json();

        if (!data.success) {
            alert('❌ プレビュー取得エラー: ' + (data.error || ''));
            closeLineNotifyModal();
            return;
        }

        currentLinePostData = data;
        currentLineBubble = data.bubble;

        // 右側情報
        document.getElementById('line-notify-depts').textContent = data.dept_display;
        document.getElementById('line-notify-linked-count').textContent = data.linked_count;
        document.getElementById('line-notify-total-count').textContent = data.total_targets;
        document.getElementById('my-line-staff-name').textContent = data.my_name || '自分';

        const myBadge = document.getElementById('my-line-badge');
        const btnTestMe = document.getElementById('btn-line-test-me');
        if (data.my_line_linked) {
            myBadge.textContent = '✅ 連携済み（テスト受信可）';
            myBadge.style.color = '#16a34a';
            btnTestMe.disabled = false;
            btnTestMe.title = 'あなたのスマホLINEに実機テスト送信します';
        } else {
            myBadge.textContent = '❌ 未連携（テスト送信不可）';
            myBadge.style.color = '#dc2626';
            btnTestMe.disabled = true;
            btnTestMe.title = '安否連絡網画面からLINE連携を行ってください';
        }

        // 左側カードプレビュー描画
        renderLineCardMock(data.bubble);

        // 注意事項入力欄の初期値
        const noticeEl = document.getElementById('line-notify-custom-notice');
        if (!noticeEl.dataset.userEdited || noticeEl.dataset.postId !== String(postId)) {
            let defaultNotice = '';
            try {
                defaultNotice = data.bubble.body.contents[3].contents[1].text || '';
            } catch(e) {}
            noticeEl.value = defaultNotice;
            noticeEl.dataset.userEdited = '';
            noticeEl.dataset.postId = String(postId);
        }

        if (loading) loading.style.display = 'none';
        if (content) content.style.display = 'grid';
    } catch (e) {
        alert('❌ プレビュー通信エラー: ' + e.message);
        closeLineNotifyModal();
    }
}

function renderLineCardMock(bubble) {
    if (!bubble) return;
    const header = bubble.header || {};
    const body = bubble.body || {};

    const pHeader = document.getElementById('preview-header');
    if (header.backgroundColor) pHeader.style.backgroundColor = header.backgroundColor;
    const catText = header.contents && header.contents[0] ? header.contents[0].text : '📋 お知らせ';
    document.getElementById('preview-cat').textContent = catText;

    const titleText = body.contents && body.contents[0] ? body.contents[0].text : 'タイトル';
    document.getElementById('preview-title').textContent = titleText;

    let dText = '-', deptText = '-';
    try {
        const slotBox = body.contents[2].contents;
        dText = slotBox[0].contents[1].text;
        deptText = slotBox[1].contents[1].text;
    } catch(e) {}
    document.getElementById('preview-date').textContent = dText;
    document.getElementById('preview-dept').textContent = deptText;

    let nText = '-';
    try {
        nText = body.contents[3].contents[1].text;
    } catch(e) {}
    document.getElementById('preview-notice').textContent = nText;
}

function updateLineModalPreview() {
    const text = document.getElementById('line-notify-custom-notice').value;
    document.getElementById('preview-notice').textContent = text || '（注意事項なし）';
    document.getElementById('line-notify-custom-notice').dataset.userEdited = '1';
}

async function executeLineTestMe() {
    if (!currentLinePostId) return;
    const btn = document.getElementById('btn-line-test-me');
    const origText = btn.textContent;
    btn.disabled = true;
    btn.textContent = '⏳ あなたのLINEへ送信中...';

    const customNotice = document.getElementById('line-notify-custom-notice').value;
    const fd = new FormData();
    fd.append('action', 'send_test_me');
    fd.append('post_id', currentLinePostId);
    fd.append('custom_notice', customNotice);

    try {
        const res = await fetch('api/line_post_notify_api.php', { method: 'POST', body: fd });
        const data = await res.json();
        btn.disabled = false;
        btn.textContent = origText;

        if (data.success) {
            if (typeof showToast === 'function') {
                showToast('📲 ' + data.message);
            } else {
                alert('📲 ' + data.message);
            }
        } else {
            const err = data.error || '送信失敗';
            if (typeof showToast === 'function') {
                showToast('❌ ' + err, 'error');
            } else {
                alert('❌ ' + err);
            }
        }
    } catch(e) {
        btn.disabled = false;
        btn.textContent = origText;
        alert('❌ 通信エラーが発生しました: ' + e.message);
    }
}

async function executeLineBroadcast(targetMode) {
    if (!currentLinePostId) return;
    const modeLabel = targetMode === 'unread_only' ? '未読・未了解のスタッフ' : '対象部署の全連携スタッフ';
    if (!confirm(`本当に【${modeLabel}】へLINEカードメッセージを一斉送信しますか？\n（※送信前に自分のLINEで確認済みであることを推奨します）`)) {
        return;
    }

    const btn = targetMode === 'unread_only' ? document.getElementById('btn-line-send-unread') : document.getElementById('btn-line-send-all');
    const origText = btn.textContent;
    btn.disabled = true;
    btn.textContent = '⏳ 一斉配信中...';

    const customNotice = document.getElementById('line-notify-custom-notice').value;
    const fd = new FormData();
    fd.append('action', 'send_broadcast');
    fd.append('post_id', currentLinePostId);
    fd.append('target_mode', targetMode);
    fd.append('custom_notice', customNotice);

    try {
        const res = await fetch('api/line_post_notify_api.php', { method: 'POST', body: fd });
        const data = await res.json();
        btn.disabled = false;
        btn.textContent = origText;

        if (data.success) {
            if (typeof showToast === 'function') {
                showToast('🚀 ' + data.message);
            } else {
                alert('🚀 ' + data.message);
            }
            closeLineNotifyModal();
        } else {
            const err = data.error || '送信失敗';
            if (typeof showToast === 'function') {
                showToast('❌ ' + err, 'error');
            } else {
                alert('❌ ' + err);
            }
        }
    } catch(e) {
        btn.disabled = false;
        btn.textContent = origText;
        alert('❌ 通信エラーが発生しました: ' + e.message);
    }
}
</script>
