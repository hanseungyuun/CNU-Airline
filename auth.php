<?php
/**
 * @file auth.php
 * @brief 사용자 로그인 및 회원가입 UI를 제공하는 페이지
 * @description
 * 1. 로그인 폼과 회원가입 폼을 HTML로 구성합니다.
 * 2. JavaScript를 사용하여 두 폼 간의 전환(toggle) 기능을 구현합니다.
 * 3. 로그인 폼 제출 시, api/login.php로 비동기 요청을 보내 인증을 처리합니다.
 *    (회원가입 기능은 현재 UI만 구현되어 있으며, 실제 기능은 연결되지 않았습니다.)
 */

// --- 세션 및 사용자 상태 설정 ---
session_start();
$is_logged_in = isset($_SESSION['cno']);
$is_admin = $is_logged_in && $_SESSION['cno'] === 'c0';
$mypage_url = $is_admin ? 'admin.php' : 'mypage.php';

$page_title = '로그인';
include 'templates/header.php';
?>

<main class="auth-page">
    <div class="login-wrapper">
        <!-- 로그인 폼 컨테이너 (기본적으로 활성화되어 보임) -->
        <div id="login-container" class="form-container active">
            <h2>로그인</h2>
            <form>
                <div class="input-group">
                    <label for="login-id">회원번호</label>
                    <input type="text" id="login-id" name="id" required>
                </div>
                <div class="input-group">
                    <label for="login-pw">비밀번호</label>
                    <input type="password" id="login-pw" name="pw" required>
                </div>
                <button type="submit" class="submit-btn">로그인</button>
                <p class="form-switcher">
                    계정이 없으신가요? <a id="show-signup">회원가입</a>
                </p>
            </form>
        </div>

        <!-- 회원가입 폼 컨테이너 (기본적으로 비활성화되어 숨겨짐) -->
        <div id="signup-container" class="form-container">
            <h2>회원가입</h2>
            <!-- 이 폼은 현재 기능이 연결되지 않은 UI 프로토타입입니다. -->
            <form>
                <div class="input-group"><label for="signup-id">회원번호</label><input type="text" id="signup-id" name="id" required></div>
                <div class="input-group"><label for="signup-name">이름</label><input type="text" id="signup-name" name="name" required></div>
                <div class="input-group"><label for="signup-email">이메일</label><input type="email" id="signup-email" name="email" required></div>
                <div class="input-group"><label for="signup-pw">비밀번호</label><input type="password" id="signup-pw" name="pw" required></div>
                <div class="input-group"><label for="signup-pw-confirm">비밀번호 확인</label><input type="password" id="signup-pw-confirm" name="pw_confirm" required></div>
                <button type="submit" class="submit-btn">회원가입</button>
                <p class="form-switcher">
                    이미 계정이 있으신가요? <a id="show-login">로그인</a>
                </p>
            </form>
        </div>
    </div>
</main>

<?php include 'templates/footer.php'; ?>

<!-- 페이지 동적 기능을 위한 JavaScript -->
<script>
    document.addEventListener('DOMContentLoaded', () => {
        // --- 1. DOM 요소 참조 ---
        const loginContainer = document.getElementById('login-container');
        const signupContainer = document.getElementById('signup-container');
        const showSignupLink = document.getElementById('show-signup');
        const showLoginLink = document.getElementById('show-login');
        const loginForm = loginContainer.querySelector('form');

        // --- 2. 로그인/회원가입 폼 전환(toggle) 로직 ---
        // '회원가입' 링크 클릭 시, 로그인 폼을 숨기고 회원가입 폼을 표시합니다.
        showSignupLink.addEventListener('click', (e) => {
            e.preventDefault();
            loginContainer.classList.remove('active');
            signupContainer.classList.add('active');
        });

        // '로그인' 링크 클릭 시, 회원가입 폼을 숨기고 로그인 폼을 표시합니다.
        showLoginLink.addEventListener('click', (e) => {
            e.preventDefault();
            signupContainer.classList.remove('active');
            loginContainer.classList.add('active');
        });

        // --- 3. 로그인 처리 로직 ---
        loginForm.addEventListener('submit', (e) => {
            // 기본 폼 제출 동작(페이지 새로고침)을 막고, JavaScript로 직접 처리합니다.
            e.preventDefault();

            // FormData 객체를 사용해 폼의 모든 입력 데이터를 쉽게 가져옵니다.
            const formData = new FormData(loginForm);

            // Fetch API를 사용하여 서버(api/login.php)에 비동기적으로 로그인 요청을 보냅니다.
            fetch('api/login.php', {
                method: 'POST',
                body: formData // FormData 객체를 body에 담아 전송
            })
            .then(response => response.json()) // 서버의 응답을 JSON 형식으로 파싱합니다.
            .then(data => {
                // 서버에서 받은 응답(data)에 따라 분기 처리합니다.
                if (data.success) {
                    // 로그인 성공 시: 알림을 표시하고 메인 페이지로 이동합니다.
                    alert('로그인 성공!');
                    window.location.href = 'main.php';
                } else {
                    // 로그인 실패 시: 서버에서 전달받은 오류 메시지를 사용자에게 보여줍니다.
                    alert('로그인 실패: ' + data.message);
                }
            })
            .catch(error => {
                // 네트워크 오류 등 fetch 요청 자체에 문제가 발생한 경우 처리합니다.
                console.error('Login Error:', error);
                alert('로그인 처리 중 오류가 발생했습니다.');
            });
        });
    });
</script>
</body>
</html>