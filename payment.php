<?php
/**
 * @file payment.php
 * @brief 항공권 결제 및 탑승객 정보 입력 페이지
 * @description
 * 1. (서버) reserve.php에서 POST로 받은 항공편 정보와 세션을 통해 사용자 정보를 DB에서 조회합니다.
 * 2. (서버) 조회된 정보를 바탕으로 결제 확인 페이지를 렌더링합니다.
 * 3. (클라이언트) '결제하기' 버튼 클릭 시, JavaScript가 api/process_payment.php로 비동기 요청을 보내
 *    실제 예약 처리를 수행하고, 그 결과를 모달로 표시합니다.
 */

session_start();
require_once 'api/db_connect.php';
require_once 'api/functions.php'; // getAirportName() 함수 사용을 위해 포함
$is_logged_in = isset($_SESSION['cno']);
$is_admin = $is_logged_in && $_SESSION['cno'] === 'c0';
$mypage_url = $is_admin ? 'admin.php' : 'mypage.php';

// --- 1. 접근 제어 ---
// 비로그인 사용자는 로그인 페이지로 리디렉션합니다.
if (!$is_logged_in) {
    header('Location: auth.php');
    exit;
}
// reserve.php를 거치지 않고 직접 접근한 경우, 필수 정보가 없으므로 예약 페이지로 돌려보냅니다.
if (!isset($_POST['flightno']) || !isset($_POST['departureDateTime']) || !isset($_POST['seatClass'])) {
    header('Location: reserve.php');
    exit;
}

// --- 2. 데이터베이스 조회 (항공편 정보, 사용자 정보) ---
$flight = null;
$user = null;
$conn = null;
try {
    $conn = new PDO($dsn, $db_username, $db_password);
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // 2-1. 선택한 항공편의 상세 정보 조회
    $sql_flight = "SELECT
                    A.AIRLINE, A.FLIGHTNO,
                    TO_CHAR(A.DEPARTUREDATETIME, 'YYYY-MM-DD\"T\"HH24:MI:SS') AS DEPARTUREDATETIME,
                    TO_CHAR(A.ARRIVALDATETIME, 'YYYY-MM-DD\"T\"HH24:MI:SS') AS ARRIVALDATETIME,
                    A.DEPARTUREAIRPORT, A.ARRIVALAIRPORT,
                    S.SEATCLASS, S.PRICE,
                    GET_REMAINING_SEATS(A.FLIGHTNO, A.DEPARTUREDATETIME, S.SEATCLASS) AS REMAINING_SEATS
                 FROM AIRPLANE A
                 JOIN SEATS S ON A.FLIGHTNO = S.FLIGHTNO AND A.DEPARTUREDATETIME = S.DEPARTUREDATETIME
                 WHERE A.FLIGHTNO = :flightno
                   AND A.DEPARTUREDATETIME = TO_TIMESTAMP(:departureDateTime, 'YYYY-MM-DD\"T\"HH24:MI:SS')
                   AND S.SEATCLASS = :seatClass";
    $stmt_flight = $conn->prepare($sql_flight);
    $stmt_flight->execute([
        ':flightno' => $_POST['flightno'],
        ':departureDateTime' => $_POST['departureDateTime'],
        ':seatClass' => $_POST['seatClass']
    ]);
    $flight = $stmt_flight->fetch(PDO::FETCH_ASSOC);

    // 항공편 정보가 조회되지 않으면 오류 메시지를 출력하고 중단합니다.
    if (!$flight) {
        die("오류: 요청하신 항공편 정보를 찾을 수 없습니다. 다시 시도해주세요.");
    }

    // 2-2. 현재 로그인된 사용자의 정보 조회
    $sql_user = "SELECT NAME, EMAIL, PASSPORTNUMBER FROM CUSTOMER WHERE CNO = :cno";
    $stmt_user = $conn->prepare($sql_user);
    $stmt_user->execute([':cno' => $_SESSION['cno']]);
    $user = $stmt_user->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        die("오류: 사용자 정보를 찾을 수 없습니다. 다시 로그인해주세요.");
    }

} catch (PDOException $e) {
    die("데이터베이스 처리 중 오류가 발생했습니다: " . $e->getMessage());
} finally {
    $conn = null;
}

// --- 소요 시간 계산  ---
$duration_str = '정보 없음'; // 기본값
if ($flight) {
    $departure_dt = new DateTime($flight['DEPARTUREDATETIME']);
    $arrival_dt = new DateTime($flight['ARRIVALDATETIME']);
    
    $interval = $departure_dt->diff($arrival_dt);
    
    // 'h시간 m분' 형식으로 변환합니다.
    $duration_str = $interval->format('%h시간 %i분');
}

$page_title = "항공권 결제";
include 'templates/header.php';
?>

<main class="content-main">
    <div class="container">
        <!-- 1. 예약 항공편 정보 패널 -->
        <div class="content-panel">
            <div class="flight-path">
                <div class="airport">
                    <div class="airport-code"><?= htmlspecialchars($flight['DEPARTUREAIRPORT']) ?></div>
                    <div class="airport-name"><?= htmlspecialchars(getAirportName($flight['DEPARTUREAIRPORT'])) ?></div>
                </div>
                <div class="flight-duration">
                    <i class="fa-solid fa-plane"></i>
                    <span><?= htmlspecialchars($duration_str) ?></span>
                </div>
                <div class="airport">
                    <div class="airport-code"><?= htmlspecialchars($flight['ARRIVALAIRPORT']) ?></div>
                    <div class="airport-name"><?= htmlspecialchars(getAirportName($flight['ARRIVALAIRPORT'])) ?></div>
                </div>
            </div>
            <div class="flight-details-grid">
                <!-- PHP로 조회한 항공편 상세 정보를 화면에 출력 -->
                <div class="detail-item"><span class="detail-label">출발시간</span><span class="detail-value"><?= (new DateTime($flight['DEPARTUREDATETIME']))->format('Y-m-d H:i') ?></span></div>
                <div class="detail-item"><span class="detail-label">도착시간</span><span class="detail-value"><?= (new DateTime($flight['ARRIVALDATETIME']))->format('Y-m-d H:i') ?> (현지)</span></div>
                <div class="detail-item"><span class="detail-label">항공사</span><span class="detail-value"><?= htmlspecialchars($flight['AIRLINE']) ?></span></div>
                <div class="detail-item"><span class="detail-label">항공편</span><span class="detail-value"><?= htmlspecialchars($flight['FLIGHTNO']) ?></span></div>
                <div class="detail-item"><span class="detail-label">좌석등급</span><span class="detail-value"><?= htmlspecialchars($flight['SEATCLASS']) ?></span></div>
                <div class="detail-item"><span class="detail-label">남은 좌석</span><span class="detail-value"><?= htmlspecialchars($flight['REMAINING_SEATS']) ?> 석</span></div>
                <div class="detail-item"><span class="detail-label">항공권 가격</span><span class="detail-value price"><?= number_format($flight['PRICE']) ?> 원</span></div>
            </div>
        </div>

        <!-- 2. 탑승객 정보 입력 패널 -->
        <div class="content-panel">
            <h2 class="panel-title">탑승객 정보 입력</h2>
            <!-- 이 폼은 실제 submit되지 않고, JS에서 입력값 확인용으로만 사용됩니다. -->
            <form id="passenger-info-form" class="passenger-form-grid">
                <!-- 이름과 이메일은 DB에서 가져온 값으로 채우고, 수정 불가능(readonly)하게 설정합니다. -->
                <div class="input-group"><label for="name">이름 (영문)</label><input type="text" id="name" value="<?= htmlspecialchars($user['NAME']) ?>" readonly></div>
                <div class="input-group"><label for="email">이메일</label><input type="email" id="email" value="<?= htmlspecialchars($user['EMAIL']) ?>" readonly></div>
                <div class="input-group"><label>성별</label><div class="radio-buttons"><label><input type="radio" name="gender" value="male" checked> 남성</label><label><input type="radio" name="gender" value="female"> 여성</label></div></div>
                <div class="input-group"><label for="dob">생년월일</label><input type="date" id="dob" required></div>
                <div class="input-group"><label for="phone">연락처</label><input type="tel" id="phone" placeholder="'-' 없이 숫자만 입력" required></div>
                <div class="input-group"><label for="passport">여권번호</label><input type="text" id="passport" value="<?= htmlspecialchars($user['PASSPORTNUMBER'] ?? '') ?>" placeholder="여권번호 입력" required></div>
            </form>
        </div>

        <!-- 3. 최종 결제 버튼 영역 -->
        <div class="payment-summary">
            <div class="final-price"><span class="label">최종 결제 금액</span><span class="amount"><?= number_format($flight['PRICE']) ?> 원</span></div>
            <!-- '결제하기' 버튼. data-* 속성을 통해 JS에서 사용할 주요 예약 정보를 저장해둡니다. -->
            <button id="process-payment-btn" class="payment-btn"
                data-flightno="<?= htmlspecialchars($flight['FLIGHTNO']) ?>"
                data-departure-datetime="<?= htmlspecialchars($flight['DEPARTUREDATETIME']) ?>"
                data-seat-class="<?= htmlspecialchars($flight['SEATCLASS']) ?>"
                data-payment="<?= htmlspecialchars($flight['PRICE']) ?>">
                결제하기
            </button>
        </div>
    </div>
</main>

<!-- 결제 성공/실패 결과를 표시할 모달 -->
<div id="payment-result-modal" class="modal-overlay">
    <div class="modal-content" style="text-align: center;">
        <div id="modal-icon"></div>
        <h2 id="modal-title" class="modal-title" style="margin-top: 15px;"></h2>
        <p id="modal-message" style="font-size: 1.1rem; margin-top: 10px;"></p>
        <ul id="modal-details" class="cancellation-details" style="text-align: left; display: none;"></ul>
        <button id="modal-confirm-btn" class="submit-btn" style="width: 100%;">확인</button>
    </div>
</div>

<?php include 'templates/footer.php'; ?>

<script>
    document.addEventListener('DOMContentLoaded', () => {
        const paymentBtn = document.getElementById('process-payment-btn');
        const paymentResultModal = document.getElementById('payment-result-modal');
        const modalIcon = document.getElementById('modal-icon');
        const modalTitle = document.getElementById('modal-title');
        const modalMessage = document.getElementById('modal-message');
        const modalDetails = document.getElementById('modal-details');
        const modalConfirmBtn = document.getElementById('modal-confirm-btn');

        if (paymentBtn) {
            paymentBtn.addEventListener('click', (e) => {
                e.preventDefault();

                // 폼 입력값 유효성 검사
                const dob = document.getElementById('dob').value;
                const phone = document.getElementById('phone').value;
                const passport = document.getElementById('passport').value;
                if (!dob || !phone || !passport) {
                    alert('모든 탑승객 정보를 입력해주세요.');
                    return;
                }

                // 핵심 로직: 중복 결제 요청 방지
                // 버튼을 비활성화하고 로딩 상태로 변경하여 사용자가 여러 번 클릭하는 것을 막습니다.
                paymentBtn.disabled = true;
                paymentBtn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> 처리 중...';

                // data-* 속성에 저장해 둔 예약 정보를 객체로 만듭니다.
                const flightData = {
                    flightno: paymentBtn.dataset.flightno,
                    departureDateTime: paymentBtn.dataset.departureDatetime,
                    seatClass: paymentBtn.dataset.seatClass,
                    payment: paymentBtn.dataset.payment
                };

                // Fetch API를 통해 서버(process_payment.php)에 예약 처리를 요청합니다.
                fetch('api/process_payment.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(flightData)
                })
                .then(response => response.json())
                .then(data => {
                    // 서버 응답에 따라 모달 내용을 동적으로 구성합니다.
                    if (data.success) {
                        // --- 예약 성공 시 ---
                        modalIcon.innerHTML = `<i class="fa-solid fa-check-circle" style="font-size: 4rem; color: #28a745;"></i>`;
                        modalTitle.textContent = '예약 완료';
                        modalMessage.innerHTML = `회원님의 이메일(<strong>${data.email}</strong>)로<br>예약 확정서가 발송되었습니다.`;
                        modalDetails.innerHTML = `<li><span class="detail-label">결제 금액</span><span class="detail-value">${Number(data.payment).toLocaleString()} 원</span></li>`;
                        modalDetails.style.display = 'block';
                        // '확인' 버튼 클릭 시, 예약 내역 페이지로 이동시킵니다.
                        modalConfirmBtn.onclick = () => window.location.href = 'reserve_history.php';
                    } else {
                        // --- 예약 실패 시 (예: 좌석 부족) ---
                        modalIcon.innerHTML = `<i class="fa-solid fa-times-circle" style="font-size: 4rem; color: #dc3545;"></i>`;
                        modalTitle.textContent = '예약 불가';
                        modalMessage.textContent = data.message; // 서버로부터 받은 실패 사유
                        modalDetails.style.display = 'none';
                        // '확인' 버튼 클릭 시, 모달만 닫습니다.
                        modalConfirmBtn.onclick = () => paymentResultModal.classList.remove('active');
                    }
                    paymentResultModal.classList.add('active');
                })
                .catch(error => {
                    console.error('Payment processing error:', error);
                    alert('죄송합니다. 서버와의 통신 중 오류가 발생했습니다.');
                })
                .finally(() => {
                    // 요청이 성공하든 실패하든 항상 버튼을 다시 활성화합니다.
                    paymentBtn.disabled = false;
                    paymentBtn.innerHTML = '결제하기';
                });
            });
        }

        // 모달의 '확인' 버튼 외 다른 방법으로 모달을 닫는 로직
        const closeModal = () => paymentResultModal.classList.remove('active');
        paymentResultModal.addEventListener('click', (e) => {
            // 예약 성공 시에는 '확인' 버튼으로만 닫히게(페이지 이동) 하여,
            // 사용자가 중요 정보를 놓치지 않도록 합니다.
            if (e.target === paymentResultModal && modalTitle.textContent !== '예약 완료') {
                closeModal();
            }
        });
    });
</script>

</body>
</html>