<?php
/**
 * @file mypage.php
 * @brief 로그인한 사용자의 개인화된 정보를 요약하여 보여주는 페이지 (대시보드)
 * @description
 * 1. (접근 제어) 비로그인 사용자는 로그인 페이지로 리디렉션합니다.
 * 2. (데이터 조회) DB에서 현재 사용자의 기본 정보, 최근 예약 내역 1건, 최근 취소 내역 1건을 조회합니다.
 * 3. (렌더링) 조회된 정보를 바탕으로 마이페이지 UI를 구성합니다.
 * 4. (부가 기능) 여권번호가 등록되지 않은 경우, 모달을 통해 등록/업데이트하는 기능을 제공합니다.
 */

// --- 세션 시작 및 상태 설정 ---
session_start();
require_once 'api/db_connect.php';
require_once 'api/functions.php'; // getAirportName() 등 공통 함수 사용
$is_logged_in = isset($_SESSION['cno']);
$is_admin = $is_logged_in && $_SESSION['cno'] === 'c0';
$mypage_url = $is_admin ? 'admin.php' : 'mypage.php';

// --- 1. 로그인 상태 확인 (게이트키퍼) ---
if (!$is_logged_in) {
    header('Location: auth.php');
    exit;
}

// --- 2. DB에서 마이페이지에 필요한 모든 정보 조회 ---
$conn = null;
$user = null;
$latest_reservation = null;
$latest_cancellation = null;

try {
    $conn = new PDO($dsn, $db_username, $db_password);
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $cno = $_SESSION['cno'];

    // 2-1. 사용자 기본 정보 조회 (이름, 이메일, 여권번호 등)
    $stmt_user = $conn->prepare("SELECT NAME, CNO, EMAIL, PASSPORTNUMBER FROM CUSTOMER WHERE CNO = :cno");
    $stmt_user->execute([':cno' => $cno]);
    $user = $stmt_user->fetch(PDO::FETCH_ASSOC);

    // 2-2. 최근 예약 내역 1건 조회
    // LEFT JOIN과 `C.CNO IS NULL` 조건을 사용해 '취소되지 않은' 예약 중에서 가장 최신 내역을 가져옵니다.
    // `FETCH FIRST 1 ROW ONLY`는 Oracle DB에서 상위 1개 행만 가져오는 효율적인 구문입니다.
    $sql_res = "SELECT A.DEPARTUREAIRPORT, A.ARRIVALAIRPORT, R.FLIGHTNO,
                    TO_CHAR(R.DEPARTUREDATETIME, 'YYYY-MM-DD\"T\"HH24:MI:SS') AS DEPARTUREDATETIME,
                    TO_CHAR(R.RESERVEDATETIME, 'YYYY-MM-DD\"T\"HH24:MI:SS') AS RESERVEDATETIME
                FROM RESERVE R
                LEFT JOIN CANCEL C ON R.CNO = C.CNO AND R.FLIGHTNO = C.FLIGHTNO AND R.DEPARTUREDATETIME = C.DEPARTUREDATETIME AND R.SEATCLASS = C.SEATCLASS
                JOIN AIRPLANE A ON R.FLIGHTNO = A.FLIGHTNO AND R.DEPARTUREDATETIME = A.DEPARTUREDATETIME
                WHERE R.CNO = :cno AND C.CNO IS NULL
                ORDER BY R.RESERVEDATETIME DESC FETCH FIRST 1 ROW ONLY";
    $stmt_res = $conn->prepare($sql_res);
    $stmt_res->execute([':cno' => $cno]);
    $latest_reservation = $stmt_res->fetch(PDO::FETCH_ASSOC);

    // 2-3. 최근 취소 내역 1건 조회
    $sql_can = "SELECT A.DEPARTUREAIRPORT, A.ARRIVALAIRPORT, C.FLIGHTNO,
                    TO_CHAR(C.DEPARTUREDATETIME, 'YYYY-MM-DD\"T\"HH24:MI:SS') AS DEPARTUREDATETIME,
                    TO_CHAR(C.CANCELDATETIME, 'YYYY-MM-DD\"T\"HH24:MI:SS') AS CANCELDATETIME
                FROM CANCEL C
                JOIN AIRPLANE A ON C.FLIGHTNO = A.FLIGHTNO AND C.DEPARTUREDATETIME = A.DEPARTUREDATETIME
                WHERE C.CNO = :cno
                ORDER BY C.CANCELDATETIME DESC FETCH FIRST 1 ROW ONLY";
    $stmt_can = $conn->prepare($sql_can);
    $stmt_can->execute([':cno' => $cno]);
    $latest_cancellation = $stmt_can->fetch(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    die("데이터베이스 연결에 실패했습니다: " . $e->getMessage());
} finally {
    $conn = null;
}

$page_title = '마이페이지';
include 'templates/header.php';
?>

<main class="content-main">
    <div class="container">
        <!-- 1. 회원 정보 패널 -->
        <div class="content-panel">
            <h2 class="panel-title"><?= htmlspecialchars($user['NAME']) ?>님의 회원 정보</h2>
            <ul class="info-list">
                <li class="info-item"><span class="info-label">이름</span><span class="info-value"><?= htmlspecialchars($user['NAME']) ?></span></li>
                <li class="info-item"><span class="info-label">회원번호 (아이디)</span><span class="info-value"><?= htmlspecialchars($user['CNO']) ?></span></li>
                <li class="info-item"><span class="info-label">이메일</span><span class="info-value"><?= htmlspecialchars($user['EMAIL']) ?></span></li>
                <li class="info-item">
                    <span class="info-label">여권번호</span>
                    <!-- 여권번호 등록 여부에 따라 다르게 표시되는 동적 영역 -->
                    <div id="passport-section" class="passport-action">
                        <?php if (!empty($user['PASSPORTNUMBER'])): ?>
                            <span class="info-value"><?= htmlspecialchars($user['PASSPORTNUMBER']) ?></span>
                        <?php else: ?>
                            <span class="info-value unregistered">등록되지 않음</span>
                            <a href="#" id="open-passport-modal" class="register-link">등록하기</a>
                        <?php endif; ?>
                    </div>
                </li>
            </ul>
        </div>

        <!-- 2. 최근 예약 내역 요약 패널 -->
        <div class="content-panel">
            <h2 class="panel-title">최근 예약 내역</h2>
            <?php if ($latest_reservation): // 조회된 예약 내역이 있을 경우에만 표시 ?>
                <div class="ticket-summary">
                    <div class="ticket-route">
                        <span><?= getAirportName($latest_reservation['DEPARTUREAIRPORT']) ?> (<?= $latest_reservation['DEPARTUREAIRPORT'] ?>)</span>
                        <i class="fa-solid fa-plane"></i>
                        <span><?= getAirportName($latest_reservation['ARRIVALAIRPORT']) ?> (<?= $latest_reservation['ARRIVALAIRPORT'] ?>)</span>
                    </div>
                    <div class="ticket-date"><?= (new DateTime($latest_reservation['DEPARTUREDATETIME']))->format('Y년 m월 d일, H:i') ?> 출발</div>
                    <div class="ticket-details"><span>항공편: <?= htmlspecialchars($latest_reservation['FLIGHTNO']) ?></span> | <span>예약일: <?= (new DateTime($latest_reservation['RESERVEDATETIME']))->format('Y-m-d') ?></span></div>
                </div>
                <a href="reserve_history.php" class="view-more-btn">예약 내역 더보기</a>
            <?php else: ?>
                <p style="text-align:center; color: #777;">최근 예약 내역이 없습니다.</p>
            <?php endif; ?>
        </div>

        <!-- 3. 최근 취소 내역 요약 패널 -->
        <div class="content-panel">
            <h2 class="panel-title">최근 취소 내역</h2>
            <?php if ($latest_cancellation): // 조회된 취소 내역이 있을 경우에만 표시 ?>
                <div class="ticket-summary">
                    <div class="ticket-route">
                        <span><?= getAirportName($latest_cancellation['DEPARTUREAIRPORT']) ?> (<?= $latest_cancellation['DEPARTUREAIRPORT'] ?>)</span>
                        <i class="fa-solid fa-plane"></i>
                        <span><?= getAirportName($latest_cancellation['ARRIVALAIRPORT']) ?> (<?= $latest_cancellation['ARRIVALAIRPORT'] ?>)</span>
                    </div>
                    <div class="ticket-date"><?= (new DateTime($latest_cancellation['DEPARTUREDATETIME']))->format('Y년 m월 d일, H:i') ?> 출발편</div>
                    <div class="ticket-details"><span>항공편: <?= htmlspecialchars($latest_cancellation['FLIGHTNO']) ?></span> | <span>취소일: <?= (new DateTime($latest_cancellation['CANCELDATETIME']))->format('Y-m-d') ?></span></div>
                </div>
                <a href="cancel_history.php" class="view-more-btn">취소 내역 더보기</a>
            <?php else: ?>
                <p style="text-align:center; color: #777;">최근 취소 내역이 없습니다.</p>
            <?php endif; ?>
        </div>
    </div>
</main>

<!-- 여권번호 등록/수정을 위한 모달 -->
<div id="passport-modal" class="modal-overlay">
    <div class="modal-content">
        <div class="modal-header"><h2 class="modal-title">여권 정보 등록</h2><button class="close-modal-btn">×</button></div>
        <div class="modal-body">
            <form id="passport-form">
                <div class="input-group"><label for="passport-number-input">여권번호</label><input type="text" id="passport-number-input" name="passport_number" placeholder="여권번호를 입력하세요 (예: M12345678)" required></div>
                <button type="submit" class="submit-btn">저장하기</button>
            </form>
        </div>
    </div>
</div>

<?php include 'templates/footer.php'; ?>

<script>
    document.addEventListener('DOMContentLoaded', () => {
        // --- 1. 모달 관련 DOM 요소 및 이벤트 리스너 설정 ---
        const passportModal = document.getElementById('passport-modal');
        const openModalBtn = document.getElementById('open-passport-modal');
        const closeModalBtns = document.querySelectorAll('.close-modal-btn');
        const passportForm = document.getElementById('passport-form');
        const passportSection = document.getElementById('passport-section');

        // '등록하기' 버튼 클릭 시 모달 표시
        if (openModalBtn) {
            openModalBtn.addEventListener('click', (e) => {
                e.preventDefault();
                passportModal.classList.add('active');
            });
        }
        // 'X' 버튼 클릭 또는 모달 외부 클릭 시 모달 숨김
        closeModalBtns.forEach(btn => btn.addEventListener('click', () => passportModal.classList.remove('active')));
        passportModal.addEventListener('click', (e) => {
            if (e.target === passportModal) passportModal.classList.remove('active');
        });

        // --- 2. 여권번호 저장 로직 ---
        passportForm.addEventListener('submit', (e) => {
            e.preventDefault(); // 기본 폼 제출 동작 방지
            const formData = new FormData(passportForm);

            // Fetch API를 사용하여 서버(api/update_passport.php)에 여권번호 업데이트를 요청합니다.
            fetch('api/update_passport.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    alert(data.message);
                    // 성공 시, 페이지 새로고침 없이 화면의 여권번호 섹션을 동적으로 업데이트합니다.
                    passportSection.innerHTML = `<span class="info-value">${data.passport}</span>`;
                    passportModal.classList.remove('active');
                } else {
                    alert('오류: ' + data.message);
                }
            })
            .catch(error => {
                console.error('Passport update error:', error);
                alert('여권번호 업데이트 중 오류가 발생했습니다.');
            });
        });
    });
</script>
</body>
</html>