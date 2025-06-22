<?php
/**
 * @file reserve_history.php
 * @brief 사용자의 전체 예약 내역을 조회하고, 예약을 취소하는 기능을 제공하는 페이지
 * @description
 * 1. (접근 제어) 비로그인 사용자는 로그인 페이지로 리디렉션합니다.
 * 2. (데이터 조회) GET 파라미터로 받은 기간 내의 '취소되지 않은' 모든 예약 내역을 DB에서 조회합니다.
 * 3. (렌더링) 조회된 예약 내역을 카드 형태로 화면에 출력하며, 출발일이 지나지 않은 예약에만 '예약 취소' 버튼을 활성화합니다.
 * 4. (예약 취소) '예약 취소' 버튼 클릭 시, JavaScript 모달에서 위약금을 계산하여 보여주고, 확정 시 API를 호출하여 취소 처리를 수행합니다.
 */

// --- 세션 시작 및 상태 설정 ---
session_start();
require_once 'api/db_connect.php';
require_once 'api/functions.php';
$is_logged_in = isset($_SESSION['cno']);
$is_admin = $is_logged_in && $_SESSION['cno'] === 'c0';
$mypage_url = $is_admin ? 'admin.php' : 'mypage.php';

// --- 1. 로그인 상태 확인 (게이트키퍼) ---
if (!$is_logged_in) {
    header('Location: auth.php');
    exit;
}

// --- 2. 기간 필터링 조건 설정 ---
// GET 파라미터로 조회 기간을 받습니다. 값이 없으면 기본값(과거 1년 ~ 미래 1년)을 사용합니다.
$start_date = $_GET['start_date'] ?? date('Y-m-d', strtotime('-1 year'));
$end_date = $_GET['end_date'] ?? date('Y-m-d', strtotime('+1 year'));

// --- 3. DB에서 예약 내역 조회 ---
$conn = null;
$reservations = [];
try {
    $conn = new PDO($dsn, $db_username, $db_password);
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // 핵심 로직: `LEFT JOIN`과 `C.CNO IS NULL`을 사용하여 취소되지 않은 예약만 필터링합니다.
    // RESERVE 테이블에는 있지만 CANCEL 테이블에는 없는 레코드를 조회하는 원리입니다.
    $sql = "SELECT 
                A.AIRLINE, R.FLIGHTNO,
                TO_CHAR(R.DEPARTUREDATETIME, 'YYYY-MM-DD\"T\"HH24:MI:SS') AS DEPARTUREDATETIME,
                TO_CHAR(A.ARRIVALDATETIME, 'YYYY-MM-DD\"T\"HH24:MI:SS') AS ARRIVALDATETIME,
                A.DEPARTUREAIRPORT, A.ARRIVALAIRPORT,
                R.SEATCLASS, R.PAYMENT,
                TO_CHAR(R.RESERVEDATETIME, 'YYYY-MM-DD\"T\"HH24:MI:SS') AS RESERVEDATETIME
            FROM 
                RESERVE R
            LEFT JOIN 
                CANCEL C ON R.CNO = C.CNO 
                         AND R.FLIGHTNO = C.FLIGHTNO 
                         AND R.DEPARTUREDATETIME = C.DEPARTUREDATETIME 
                         AND R.SEATCLASS = C.SEATCLASS
            JOIN SEATS S ON R.FLIGHTNO = S.FLIGHTNO AND R.DEPARTUREDATETIME = S.DEPARTUREDATETIME AND R.SEATCLASS = S.SEATCLASS
            JOIN AIRPLANE A ON S.FLIGHTNO = A.FLIGHTNO AND S.DEPARTUREDATETIME = A.DEPARTUREDATETIME
            WHERE
                R.CNO = :cno
                AND TRUNC(R.RESERVEDATETIME) BETWEEN TO_DATE(:start_date, 'YYYY-MM-DD') AND TO_DATE(:end_date, 'YYYY-MM-DD')
                AND C.CNO IS NULL -- 이 조건으로 '취소되지 않은' 예약을 확정합니다.
            ORDER BY R.DEPARTUREDATETIME ASC"; // 출발일 순으로 정렬

    $stmt = $conn->prepare($sql);
    $stmt->execute([
        ':cno' => $_SESSION['cno'],
        ':start_date' => $start_date,
        ':end_date' => $end_date
    ]);
    $reservations = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    die("DB 오류: " . $e->getMessage());
} finally {
    $conn = null;
}

$page_title = '예약 내역';
include 'templates/header.php';
?>

<main class="content-main">
    <div class="container">
        <!-- 기간 필터링 폼 -->
        <form action="reserve_history.php" method="GET">
            <div class="content-panel filter-panel">
                <label for="start-date">조회 기간:</label>
                <input type="date" name="start_date" value="<?= htmlspecialchars($start_date) ?>">
                <span>~</span>
                <input type="date" name="end_date" value="<?= htmlspecialchars($end_date) ?>">
                <button type="submit" class="search-btn">조회</button>
                <a href="cancel_history.php" class="link-to-cancel">취소 내역 보기</a>
            </div>
        </form>

        <!-- 예약 내역이 있을 경우, 각 예약을 카드로 표시 -->
        <?php if (count($reservations) > 0): ?>
            <?php foreach ($reservations as $res): ?>
                <?php
                // PHP에서 출발일시와 현재를 비교하여 취소 가능 여부를 미리 판단합니다.
                $is_cancellable = (new DateTime($res['DEPARTUREDATETIME'])) > (new DateTime());
                ?>
                <div class="content-panel reservation-card">
                    <div class="card-body">
                        <!-- 항공편 경로 및 상세 정보 표시 -->
                        <div class="flight-path">
                            <div class="airport"><div class="airport-code"><?= htmlspecialchars($res['DEPARTUREAIRPORT']) ?></div><div class="airport-name"><?= htmlspecialchars(getAirportName($res['DEPARTUREAIRPORT'])) ?></div></div>
                            <div class="flight-duration"><i class="fa-solid fa-plane"></i></div>
                            <div class="airport"><div class="airport-code"><?= htmlspecialchars($res['ARRIVALAIRPORT']) ?></div><div class="airport-name"><?= htmlspecialchars(getAirportName($res['ARRIVALAIRPORT'])) ?></div></div>
                        </div>
                        <div class="flight-details-grid">
                            <div class="detail-item"><span class="detail-label">항공사</span><span class="detail-value"><?= htmlspecialchars($res['AIRLINE']) ?></span></div>
                            <div class="detail-item"><span class="detail-label">항공편</span><span class="detail-value"><?= htmlspecialchars($res['FLIGHTNO']) ?></span></div>
                            <div class="detail-item"><span class="detail-label">출발시간</span><span class="detail-value"><?= (new DateTime($res['DEPARTUREDATETIME']))->format('Y-m-d H:i') ?></span></div>
                            <div class="detail-item"><span class="detail-label">도착시간</span><span class="detail-value"><?= (new DateTime($res['ARRIVALDATETIME']))->format('Y-m-d H:i') ?></span></div>
                            <div class="detail-item"><span class="detail-label">좌석 등급</span><span class="detail-value"><?= htmlspecialchars($res['SEATCLASS']) ?></span></div>
                            <div class="detail-item"><span class="detail-label">결제 금액</span><span class="detail-value price"><?= number_format($res['PAYMENT']) ?> 원</span></div>
                        </div>
                    </div>
                    <div class="card-footer">
                        <span class="booking-date">예약일: <?= (new DateTime($res['RESERVEDATETIME']))->format('Y-m-d') ?></span>
                        <!-- 취소 가능 여부에 따라 버튼을 다르게 렌더링합니다. -->
                        <?php if ($is_cancellable): ?>
                            <button class="cancel-btn"
                                data-flightno="<?= htmlspecialchars($res['FLIGHTNO']) ?>"
                                data-departure-datetime="<?= htmlspecialchars($res['DEPARTUREDATETIME']) ?>"
                                data-seat-class="<?= htmlspecialchars($res['SEATCLASS']) ?>"
                                data-payment="<?= htmlspecialchars($res['PAYMENT']) ?>">
                                예약 취소
                            </button>
                        <?php else: ?>
                            <button class="cancel-btn disabled" disabled>취소 불가</button>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php else: ?>
            <div class="content-panel" style="text-align:center;">조회된 예약 내역이 없습니다.</div>
        <?php endif; ?>
    </div>
</main>

<!-- 예약 취소 확인 모달 -->
<div id="cancellation-modal" class="modal-overlay">
    <div class="modal-content">
        <div class="modal-header"><h2 class="modal-title">예약 취소 확인</h2><button class="close-modal-btn">×</button></div>
        <div class="modal-body">
            <p>아래 항공권의 예약을 정말로 취소하시겠습니까?</p>
            <ul id="cancellation-details-list" class="cancellation-details">
                <!-- JavaScript가 위약금 정보를 동적으로 채웁니다. -->
            </ul>
            <button id="confirm-cancel-btn" class="confirm-cancel-btn">예약 취소 확정</button>
        </div>
    </div>
</div>

<?php include 'templates/footer.php'; ?>

<script>
    document.addEventListener('DOMContentLoaded', () => {
        // --- 1. DOM 요소 및 변수 설정 ---
        const cancellationModal = document.getElementById('cancellation-modal');
        const cancelBtns = document.querySelectorAll('.cancel-btn:not(.disabled)');
        const closeModalBtn = cancellationModal.querySelector('.close-modal-btn');
        let currentCancellationData = {}; // 현재 취소하려는 예약 정보를 저장할 객체

        // --- 2. '예약 취소' 버튼 이벤트 리스너 설정 ---
        cancelBtns.forEach(btn => {
            btn.addEventListener('click', () => {
                // 클릭된 버튼의 data-* 속성에서 취소에 필요한 정보를 가져옵니다.
                currentCancellationData = {
                    flightno: btn.dataset.flightno,
                    departureDateTime: btn.dataset.departureDatetime,
                    seatClass: btn.dataset.seatClass,
                    payment: parseFloat(btn.dataset.payment)
                };

                // 핵심 로직: 위약금 계산 및 모달 내용 동적 생성
                // 서버의 위약금 정책과 동일한 로직을 클라이언트에서도 구현하여 사용자에게 미리 정보를 제공합니다.
                const departureDate = new Date(currentCancellationData.departureDateTime);
                const nowDate = new Date();
                departureDate.setHours(0, 0, 0, 0); // 정확한 날짜 차이 계산을 위해 시간 초기화
                nowDate.setHours(0, 0, 0, 0);

                const diffTime = departureDate.getTime() - nowDate.getTime();
                const daysDiff = Math.ceil(diffTime / (1000 * 60 * 60 * 24));

                let penalty = 0;
                const payment = currentCancellationData.payment;
                if (daysDiff <= 0) penalty = payment;
                else if (daysDiff <= 3) penalty = 250000;
                else if (daysDiff <= 14) penalty = 180000;
                else penalty = 150000;

                let refund = payment - penalty > 0 ? payment - penalty : 0;
                
                // 계산된 정보를 바탕으로 모달의 상세 내역을 채웁니다.
                const detailsList = document.getElementById('cancellation-details-list');
                detailsList.innerHTML = `
                    <li><span class="detail-label">항공편</span><span class="detail-value">${currentCancellationData.flightno}</span></li><hr>
                    <li><span class="detail-label">결제 금액</span><span class="detail-value">${payment.toLocaleString()} 원</span></li>
                    <li><span class="detail-label">취소 위약금</span><span class="detail-value">- ${penalty.toLocaleString()} 원</span></li>
                    <li class="refund-amount"><span class="detail-label">최종 환불 금액</span><span class="detail-value">${refund.toLocaleString()} 원</span></li>
                `;
                cancellationModal.classList.add('active'); // 모달 활성화
            });
        });

        // --- 3. 모달 닫기 이벤트 ---
        closeModalBtn.addEventListener('click', () => cancellationModal.classList.remove('active'));

        // --- 4. '예약 취소 확정' 버튼 클릭 이벤트 ---
        document.getElementById('confirm-cancel-btn').addEventListener('click', () => {
            // Fetch API를 사용하여 서버(api/cancel_reservation.php)에 취소 처리를 요청합니다.
            fetch('api/cancel_reservation.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(currentCancellationData)
            })
            .then(res => res.json())
            .then(data => {
                alert(data.message); // 서버 응답 메시지를 알림으로 표시
                if (data.success) {
                    window.location.reload(); // 성공 시 페이지를 새로고침하여 목록을 갱신
                }
            })
            .catch(error => console.error('Cancellation error:', error));
        });
    });
</script>
</body>
</html>