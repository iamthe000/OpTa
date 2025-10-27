<?php
session_start();

// データディレクトリの作成
$dataDir = __DIR__ . '/data';
if (!file_exists($dataDir)) {
    mkdir($dataDir, 0755, true);
}

// JSONデータベースファイル
$usersFile = $dataDir . '/users.json';
$friendsFile = $dataDir . '/friends.json';
$messagesFile = $dataDir . '/messages.json';

// 初期化
function initFiles() {
    global $usersFile, $friendsFile, $messagesFile;
    if (!file_exists($usersFile)) file_put_contents($usersFile, json_encode([]));
    if (!file_exists($friendsFile)) file_put_contents($friendsFile, json_encode([]));
    if (!file_exists($messagesFile)) file_put_contents($messagesFile, json_encode([]));
}

initFiles();

// データ読み込み
function loadData($file) {
    return json_decode(file_get_contents($file), true);
}

// データ保存
function saveData($file, $data) {
    file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}

// ユーザー登録
if (isset($_POST['register'])) {
    $username = trim($_POST['username']);
    $password = $_POST['password'];
    
    $users = loadData($usersFile);
    
    // 重複チェック
    $exists = false;
    foreach ($users as $user) {
        if ($user['username'] === $username) {
            $exists = true;
            break;
        }
    }
    
    if (!$exists && !empty($username) && !empty($password)) {
        $userId = uniqid('user_', true);
        $users[] = [
            'id' => $userId,
            'username' => $username,
            'password' => password_hash($password, PASSWORD_DEFAULT),
            'nickname' => $username,
            'bio' => '',
            'created_at' => date('Y-m-d H:i:s')
        ];
        saveData($usersFile, $users);
        $_SESSION['message'] = '登録成功！ログインしてください。';
    } else {
        $_SESSION['message'] = 'ユーザー名が既に存在するか、入力が無効です。';
    }
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}

// ログイン
if (isset($_POST['login'])) {
    $username = trim($_POST['username']);
    $password = $_POST['password'];
    
    $users = loadData($usersFile);
    
    foreach ($users as $user) {
        if ($user['username'] === $username && password_verify($password, $user['password'])) {
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['username'] = $user['username'];
            header('Location: ' . $_SERVER['PHP_SELF']);
            exit;
        }
    }
    
    $_SESSION['message'] = 'ログインに失敗しました。';
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}

// ログアウト
if (isset($_GET['logout'])) {
    session_destroy();
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}

// プロフィール更新
if (isset($_POST['update_profile'])) {
    $nickname = trim($_POST['nickname']);
    $bio = trim($_POST['bio']);
    
    $users = loadData($usersFile);
    foreach ($users as &$user) {
        if ($user['id'] === $_SESSION['user_id']) {
            $user['nickname'] = $nickname;
            $user['bio'] = $bio;
            break;
        }
    }
    saveData($usersFile, $users);
    $_SESSION['message'] = 'プロフィールを更新しました。';
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}

// フレンド追加
if (isset($_POST['add_friend'])) {
    $friendUsername = trim($_POST['friend_username']);
    $users = loadData($usersFile);
    $friends = loadData($friendsFile);
    
    $friendId = null;
    foreach ($users as $user) {
        if ($user['username'] === $friendUsername) {
            $friendId = $user['id'];
            break;
        }
    }
    
    if ($friendId && $friendId !== $_SESSION['user_id']) {
        $friendKey = $_SESSION['user_id'] . '_' . $friendId;
        if (!isset($friends[$friendKey])) {
            $friends[$friendKey] = [
                'user1' => $_SESSION['user_id'],
                'user2' => $friendId,
                'created_at' => date('Y-m-d H:i:s')
            ];
            saveData($friendsFile, $friends);
            $_SESSION['message'] = 'フレンドを追加しました。';
        }
    } else {
        $_SESSION['message'] = 'ユーザーが見つかりません。';
    }
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}

// メッセージ送信
if (isset($_POST['send_message'])) {
    $toUserId = $_POST['to_user'];
    $message = trim($_POST['message']);
    
    if (!empty($message)) {
        $messages = loadData($messagesFile);
        $messages[] = [
            'id' => uniqid('msg_', true),
            'from' => $_SESSION['user_id'],
            'to' => $toUserId,
            'message' => $message,
            'timestamp' => date('Y-m-d H:i:s')
        ];
        saveData($messagesFile, $messages);
    }
    header('Location: ' . $_SERVER['PHP_SELF'] . '?chat=' . $toUserId);
    exit;
}

// 新しいエンドポイント: メッセージの取得
if (isset($_GET['get_messages'])) {
    header('Content-Type: application/json');
    if (isset($_GET['user_id']) && isset($_SESSION['user_id'])) {
        $messages = getMessages($_SESSION['user_id'], $_GET['user_id']);
        echo json_encode($messages);
    } else {
        echo json_encode([]);
    }
    exit;
}

// 現在のユーザー情報取得
$currentUser = null;
if (isset($_SESSION['user_id'])) {
    $users = loadData($usersFile);
    foreach ($users as $user) {
        if ($user['id'] === $_SESSION['user_id']) {
            $currentUser = $user;
            break;
        }
    }
}

// フレンドリスト取得
function getFriends($userId) {
    global $usersFile, $friendsFile;
    $users = loadData($usersFile);
    $friends = loadData($friendsFile);
    $friendList = [];
    
    foreach ($friends as $friendship) {
        $friendId = null;
        if ($friendship['user1'] === $userId) {
            $friendId = $friendship['user2'];
        } elseif ($friendship['user2'] === $userId) {
            $friendId = $friendship['user1'];
        }
        
        if ($friendId) {
            foreach ($users as $user) {
                if ($user['id'] === $friendId) {
                    $friendList[] = $user;
                    break;
                }
            }
        }
    }
    
    return $friendList;
}

// メッセージ取得
function getMessages($user1, $user2) {
    global $messagesFile;
    $messages = loadData($messagesFile);
    $conversation = [];
    
    foreach ($messages as $msg) {
        if (($msg['from'] === $user1 && $msg['to'] === $user2) ||
            ($msg['from'] === $user2 && $msg['to'] === $user1)) {
            $conversation[] = $msg;
        }
    }
    
    return $conversation;
}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>OpTa-v3.0.0 - チャットサービス</title>
    <link rel="apple-touch-icon" sizes="192x192" href="icon192x192.png">
    <link rel="icon" type="image/x-icon" href="icon.png">
    <link rel="manifest" href="manifest.json">
    <script>
    if ('serviceWorker' in navigator) {
        navigator.serviceWorker.register('sw.js');
    }
    </script>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            background: linear-gradient(135deg, #bccddb 0%, #a06800ff 100%);
            min-height: 100vh;
            display: flex;
            justify-content: center;
            align-items: center;
            padding: 20px;
        }
        
        .container {
            background: white;
            border-radius: 20px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
            max-width: 1200px;
            width: 100%;
            overflow: hidden;
        }
        
        /* ハンバーガーメニュー */
        .hamburger {
            display: none;
            cursor: pointer;
            padding: 10px;
            z-index: 1000;
        }
        
        .hamburger span {
            display: block;
            width: 25px;
            height: 3px;
            background: white;
            margin: 5px 0;
            transition: 0.3s;
        }
        
        @media (max-width: 768px) {
            .hamburger {
                display: block;
            }
            
            .sidebar {
                position: fixed;
                left: -300px;
                top: 0;
                height: 100vh;
                background: white;
                transition: 0.3s;
                z-index: 999;
            }
            
            .sidebar.active {
                left: 0;
            }
            
            .main-content {
                flex-direction: column;
            }
            
            body.menu-open {
                overflow: hidden;
            }
            
            .overlay {
                display: none;
                position: fixed;
                top: 0;
                left: 0;
                width: 100%;
                height: 100%;
                background: rgba(0,0,0,0.5);
                z-index: 998;
            }
            
            .overlay.active {
                display: block;
            }
        }
        
        .header {
            background: linear-gradient(135deg, #2586d5ff 0%, #ffa500 100%);
            color: white;
            padding: 20px 30px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .logo {
            font-size: 28px;
            font-weight: bold;
            letter-spacing: 2px;
        }
        
        .version {
            font-size: 12px;
            opacity: 0.8;
            margin-left: 10px;
        }
        
        .login-container {
            padding: 50px;
            max-width: 500px;
            margin: 0 auto;
        }
        
        .form-group {
            margin-bottom: 20px;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 8px;
            color: #333;
            font-weight: 500;
        }
        
        .form-group input, .form-group textarea {
            width: 100%;
            padding: 12px;
            border: 2px solid #e0e0e0;
            border-radius: 8px;
            font-size: 14px;
            transition: border-color 0.3s;
        }
        
        .form-group input:focus, .form-group textarea:focus {
            outline: none;
            border-color: #bccddb;
        }
        
        .btn {
            background: linear-gradient(135deg, #bccddb 0%, #ffa500 100%);
            color: white;
            border: none;
            padding: 12px 30px;
            border-radius: 8px;
            cursor: pointer;
            font-size: 14px;
            font-weight: 600;
            transition: transform 0.2s;
            width: 100%;
        }
        
        .btn:hover {
            transform: translateY(-2px);
        }
        
        .btn-secondary {
            background: #f0f0f0;
            color: #333;
            margin-top: 10px;
        }
        
        .message {
            background: #e8f4f8;
            padding: 12px;
            border-radius: 8px;
            margin-bottom: 20px;
            border-left: 4px solid #bccddb;
        }
        
        .main-content {
            display: flex;
            height: 600px;
        }
        
        .sidebar {
            width: 300px;
            border-right: 1px solid #e0e0e0;
            overflow-y: auto;
        }
        
        .tab-buttons {
            display: flex;
            border-bottom: 1px solid #e0e0e0;
        }
        
        .tab-btn {
            flex: 1;
            padding: 15px;
            background: white;
            border: none;
            cursor: pointer;
            font-weight: 600;
            transition: background 0.3s;
        }
        
        .tab-btn.active {
            background: #f8f9fa;
            border-bottom: 3px solid #bccddb;
        }
        
        .friend-list, .profile-section {
            padding: 15px;
        }
        
        .friend-item {
            padding: 15px;
            border-bottom: 1px solid #f0f0f0;
            cursor: pointer;
            transition: background 0.3s;
            display: flex;
            align-items: center;
        }
        
        .friend-item:hover {
            background: #f8f9fa;
        }
        
        .friend-avatar {
            width: 45px;
            height: 45px;
            border-radius: 50%;
            background: linear-gradient(135deg, #bccddb 0%, #ffa500 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: bold;
            margin-right: 12px;
        }
        
        .friend-info {
            flex: 1;
        }
        
        .friend-nickname {
            font-weight: 600;
            margin-bottom: 3px;
        }
        
        .friend-username {
            font-size: 12px;
            color: #888;
        }
        
        .chat-area {
            flex: 1;
            display: flex;
            flex-direction: column;
        }
        
        .chat-header {
            padding: 20px;
            border-bottom: 1px solid #e0e0e0;
            background: #f8f9fa;
        }
        
        .chat-messages {
            flex: 1;
            overflow-y: auto;
            padding: 20px;
            background: #fafafa;
        }
        
        .message-bubble {
            max-width: 70%;
            margin-bottom: 15px;
            padding: 12px 16px;
            border-radius: 18px;
            word-wrap: break-word;
        }
        
        .message-sent {
            background: linear-gradient(135deg, #bccddb 0%, #ffa500 100%);
            color: white;
            margin-left: auto;
            text-align: right;
        }
        
        .message-received {
            background: white;
            color: #333;
            box-shadow: 0 2px 5px rgba(0,0,0,0.1);
        }
        
        .message-time {
            font-size: 11px;
            opacity: 0.7;
            margin-top: 5px;
        }
        
        .chat-input {
            padding: 20px;
            border-top: 1px solid #e0e0e0;
            display: flex;
            gap: 10px;
        }
        
        .chat-input input {
            flex: 1;
            padding: 12px;
            border: 2px solid #e0e0e0;
            border-radius: 25px;
            font-size: 14px;
        }
        
        .chat-input button {
            padding: 12px 25px;
            border-radius: 25px;
        }
        
        .empty-state {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            height: 100%;
            color: #888;
        }
        
        .empty-state-icon {
            font-size: 64px;
            margin-bottom: 20px;
        }
        
        .hidden {
            display: none;
        }
        
        .user-header {
            display: flex;
            align-items: center;
            gap: 10px;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <div class="user-header">
                <?php if ($currentUser): ?>
                <div class="hamburger" onclick="toggleMenu()">
                    <span></span>
                    <span></span>
                    <span></span>
                </div>
                <?php endif; ?>
                <div class="logo">OpTa<span class="version">v3.0.1</span></div>
            </div>
            <?php if ($currentUser): ?>
                <a href="?logout" style="color: white; text-decoration: none;">ログアウト</a>
            <?php endif; ?>
        </div>
        
        <?php if (!$currentUser): ?>
            <div class="login-container">
                <?php if (isset($_SESSION['message'])): ?>
                    <div class="message"><?= htmlspecialchars($_SESSION['message']) ?></div>
                    <?php unset($_SESSION['message']); ?>
                <?php endif; ?>
                
                <h2 style="margin-bottom: 30px; text-align: center;">ログイン</h2>
                <form method="POST">
                    <div class="form-group">
                        <label>ユーザー名</label>
                        <input type="text" name="username" required>
                    </div>
                    <div class="form-group">
                        <label>パスワード</label>
                        <input type="password" name="password" required>
                    </div>
                    <button type="submit" name="login" class="btn">ログイン</button>
                </form>
                
                <h2 style="margin: 40px 0 30px; text-align: center;">新規登録</h2>
                <form method="POST">
                    <div class="form-group">
                        <label>ユーザー名（ID）</label>
                        <input type="text" name="username" required>
                    </div>
                    <div class="form-group">
                        <label>パスワード</label>
                        <input type="password" name="password" required>
                    </div>
                    <button type="submit" name="register" class="btn">登録</button>
                </form>
            </div>
        <?php else: ?>
            <div class="main-content">
                <div class="sidebar">
                    <div class="tab-buttons">
                        <button class="tab-btn active" onclick="showTab('friends')">フレンド</button>
                        <button class="tab-btn" onclick="showTab('profile')">プロフィール</button>
                    </div>
                    
                    <div id="friends-tab" class="friend-list">
                        <?php if (isset($_SESSION['message'])): ?>
                            <div class="message" style="margin: 10px;"><?= htmlspecialchars($_SESSION['message']) ?></div>
                            <?php unset($_SESSION['message']); ?>
                        <?php endif; ?>
                        
                        <form method="POST" style="margin-bottom: 20px;">
                            <div class="form-group">
                                <label>フレンド追加（ユーザー名）</label>
                                <input type="text" name="friend_username" required>
                            </div>
                            <button type="submit" name="add_friend" class="btn">追加</button>
                        </form>
                        
                        <h3 style="margin-bottom: 15px;">フレンドリスト</h3>
                        <?php
                        $friends = getFriends($_SESSION['user_id']);
                        if (empty($friends)): ?>
                            <p style="color: #888; text-align: center; padding: 20px;">フレンドがいません</p>
                        <?php else:
                            foreach ($friends as $friend): ?>
                                <div class="friend-item" onclick="location.href='?chat=<?= $friend['id'] ?>'">
                                    <div class="friend-avatar"><?= mb_substr($friend['nickname'], 0, 1) ?></div>
                                    <div class="friend-info">
                                        <div class="friend-nickname"><?= htmlspecialchars($friend['nickname']) ?></div>
                                        <div class="friend-username">@<?= htmlspecialchars($friend['username']) ?></div>
                                    </div>
                                </div>
                            <?php endforeach;
                        endif; ?>
                    </div>
                    
                    <div id="profile-tab" class="profile-section hidden">
                        <h3 style="margin-bottom: 20px;">プロフィール設定</h3>
                        <form method="POST">
                            <div class="form-group">
                                <label>ニックネーム</label>
                                <input type="text" name="nickname" value="<?= htmlspecialchars($currentUser['nickname']) ?>" required>
                            </div>
                            <div class="form-group">
                                <label>自己紹介</label>
                                <textarea name="bio" rows="4"><?= htmlspecialchars($currentUser['bio']) ?></textarea>
                            </div>
                            <div class="form-group">
                                <label>ユーザー名（変更不可）</label>
                                <input type="text" value="<?= htmlspecialchars($currentUser['username']) ?>" disabled>
                            </div>
                            <button type="submit" name="update_profile" class="btn">更新</button>
                        </form>
                    </div>
                </div>
                
                <div class="chat-area">
                    <?php if (isset($_GET['chat'])): 
                        $chatUserId = $_GET['chat'];
                        $users = loadData($usersFile);
                        $chatUser = null;
                        foreach ($users as $user) {
                            if ($user['id'] === $chatUserId) {
                                $chatUser = $user;
                                break;
                            }
                        }
                        
                        if ($chatUser): 
                            $messages = getMessages($_SESSION['user_id'], $chatUserId);
                    ?>
                        <div class="chat-header">
                            <div class="friend-nickname" style="font-size: 18px;"><?= htmlspecialchars($chatUser['nickname']) ?></div>
                            <div class="friend-username">@<?= htmlspecialchars($chatUser['username']) ?></div>
                            <?php if (!empty($chatUser['bio'])): ?>
                                <div style="margin-top: 5px; font-size: 13px; color: #666;"><?= htmlspecialchars($chatUser['bio']) ?></div>
                            <?php endif; ?>
                        </div>
                        
                        <div class="chat-messages">
                            <?php foreach ($messages as $msg): ?>
                                <div class="message-bubble <?= $msg['from'] === $_SESSION['user_id'] ? 'message-sent' : 'message-received' ?>">
                                    <?= htmlspecialchars($msg['message']) ?>
                                    <div class="message-time"><?= date('H:i', strtotime($msg['timestamp'])) ?></div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        
                        <form method="POST" class="chat-input">
                            <input type="hidden" name="to_user" value="<?= $chatUserId ?>">
                            <input type="text" name="message" placeholder="メッセージを入力..." required>
                            <button type="submit" name="send_message" class="btn">送信</button>
                        </form>
                    <?php endif; ?>
                    <?php else: ?>
                        <div class="empty-state">
                            <div class="empty-state-icon">💬</div>
                            <h3>チャットを開始しましょう</h3>
                            <p>左のフレンドリストから選択してください</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>
    </div>
    
    <script>
        function showTab(tab) {
            const friendsTab = document.getElementById('friends-tab');
            const profileTab = document.getElementById('profile-tab');
            const buttons = document.querySelectorAll('.tab-btn');
            
            buttons.forEach(btn => btn.classList.remove('active'));
            
            if (tab === 'friends') {
                friendsTab.classList.remove('hidden');
                profileTab.classList.add('hidden');
                buttons[0].classList.add('active');
            } else {
                friendsTab.classList.add('hidden');
                profileTab.classList.remove('hidden');
                buttons[1].classList.add('active');
            }
        }
        
        // チャットメッセージを最下部にスクロール
        const chatMessages = document.querySelector('.chat-messages');
        if (chatMessages) {
            chatMessages.scrollTop = chatMessages.scrollHeight;
            
            // リアルタイムメッセージ更新
            const urlParams = new URLSearchParams(window.location.search);
            const chatUserId = urlParams.get('chat');
            
            if (chatUserId) {
                let lastMessageCount = chatMessages.children.length;
                
                setInterval(async () => {
                    try {
                        const response = await fetch(`?get_messages&user_id=${chatUserId}`);
                        const messages = await response.json();
                        
                        if (messages.length > lastMessageCount) {
                            const fragment = document.createDocumentFragment();
                            
                            messages.slice(lastMessageCount).forEach(msg => {
                                const div = document.createElement('div');
                                div.className = `message-bubble ${msg.from === '<?= $_SESSION['user_id'] ?>' ? 'message-sent' : 'message-received'}`;
                                
                                const msgText = document.createTextNode(msg.message);
                                div.appendChild(msgText);
                                
                                const timeDiv = document.createElement('div');
                                timeDiv.className = 'message-time';
                                timeDiv.textContent = new Date(msg.timestamp).toLocaleTimeString('ja-JP', {hour: '2-digit', minute: '2-digit'});
                                div.appendChild(timeDiv);
                                
                                fragment.appendChild(div);
                            });
                            
                            chatMessages.appendChild(fragment);
                            chatMessages.scrollTop = chatMessages.scrollHeight;
                            lastMessageCount = messages.length;
                        }
                    } catch (error) {
                        console.error('メッセージの更新に失敗しました:', error);
                    }
                }, 3000); // 3秒ごとに更新
            }
        }
        
        // ハンバーガーメニューの制御
        function toggleMenu() {
            const sidebar = document.querySelector('.sidebar');
            const hamburger = document.querySelector('.hamburger');
            const body = document.body;
            const overlay = document.createElement('div');
            overlay.className = 'overlay';
            
            if (!sidebar.classList.contains('active')) {
                sidebar.classList.add('active');
                hamburger.classList.add('active');
                body.classList.add('menu-open');
                if (!document.querySelector('.overlay')) {
                    document.body.appendChild(overlay);
                }
                setTimeout(() => overlay.classList.add('active'), 0);
                
                overlay.addEventListener('click', toggleMenu);
            } else {
                sidebar.classList.remove('active');
                hamburger.classList.remove('active');
                body.classList.remove('menu-open');
                const existingOverlay = document.querySelector('.overlay');
                if (existingOverlay) {
                    existingOverlay.classList.remove('active');
                    setTimeout(() => existingOverlay.remove(), 300);
                }
            }
        }
    </script>
</body>
</html>
