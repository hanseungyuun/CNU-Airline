<?php
/**
 * @file cancel_history.php
 * @brief 사용자의 전체 취소 내역을 조회하는 페이지
 * @description
 * 1. (접근 제어) 비로그인 사용자는 로그인 페이지로 리디렉션합니다.
 * 2. (데이터 조회) GET 파라미터로 받은 기간 내의 모든 취소 내역을 CANCEL 테이블에서 조회합니다.
 * 3. (렌더링) 조회된 취소 내역을 카드 형태로 화면에 출력합니다.
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
// GET 파라미터로 조회 기간을 받으며, 기본값은 오늘 기준 과거 1년부터 미래 1년까지입니다.
$start_date = $_GET['start_date'] ?? date('Y-m-d', strtotime('-1 year'));
$end_date = $_GET['end_date'] ?? date('Y-m-d', strtotime('+1 year'));

// --- 3. DB에서 취소 내역 조회 ---
$conn = null;
$cancellations = [];
try {
    $conn = new PDO($dsn, $db_username, $db_password);
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // CANCEL 테이블을 중심으로 SEATS, AIRPLANE 테이블을 조인하여 필요한 정보를 가져옵니다.
    // 원래 결제 금액(PRICE)을 함께 조회하여 위약금을 계산하는 데 사용합니다.
    $sql = "SELECT
                A.AIRLINE, C.FLIGHTNO,
                TO_CHAR(C.DEPARTUREDATETIME, 'YYYY-MM-DD\"T\"HH24:MI:SS') AS DEPARTUREDATETIME,
                TO_CHAR(A.ARRIVALDATETIME, 'YYYY-MM-DD\"T\"HH24:MI:SS') AS ARRIVALDATETIME,
                A.DEPARTUREAIRPORT, A.ARRIVALAIRPORT,
                C.SEATCLASS, C.REFUND, S.PRICE,
                TO_CHAR(C.CANCELDATETIME, 'YYYY-MM-DD\"T\"HH24:MI:SS') AS CANCELDATETIME
            FROM CANCEL C
            JOIN SEATS S ON C.FLIGHTNO = S.FLIGHTNO AND C.DEPARTUREDATETIME = S.DEPARTUREDATETIME AND C.SEATCLASS = S.SEATCLASS
            JOIN AIRPLANE A ON S.FLIGHTNO = A.FLIGHTNO AND S.DEPARTUREDATETIME = A.DEPARTUREDATETIME
            WHERE C.CNO = :cno
              AND TRUNC(C.CANCELDATETIME) BETWEEN TO_DATE(:start_date, 'YYYY-MM-DD') AND TO_DATE(:end_date, 'YYYY-MM-DD')
            ORDER BY C.CANCELDATETIME DESC"; // 최근 취소 순으로 정렬

    $stmt = $conn->prepare($sql);
    $stmt->execute([
        ':cno' => $_SESSION['cno'],
        ':start_date' => $start_date,
        ':end_date' => $end_date
    ]);
    $cancellations = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    die("DB 오류: " . $e->getMessage());
} finally {
    $conn = null;
}

$page_title = '취소 내역';
include 'templates/header.php';
?>

<main class="content-main">
    <div class="container">
        <!-- 기간 필터링 폼 -->
        <form action="cancel_history.php" method="GET">
            <div class="content-panel filter-panel">
                <label for="start-date">조회 기간:</label>
                <input type="date" name="start_date" value="<?= htmlspecialchars($start_date) ?>">
                <span>~</span>
                <input type="date" name="end_date" value="<?= htmlspecialchars($end_date) ?>">
                <button type="submit" class="search-btn">조회</button>
                <a href="reserve_history.php" class="link-to-cancel">예약 내역 보기</a>
            </div>
        </form>

        <!-- 조회된 취소 내역이 있을 경우, 각 내역을 카드로 표시 -->
        <?php if (count($cancellations) > 0): ?>
            <?php foreach ($cancellations as $can): ?>
                <div class="content-panel reservation-card">
                    <div class="card-body">
                        <!-- 항공편 경로 및 상세 정보 표시 -->
                        <div class="flight-path">
                            <div class="airport"><div class="airport-code"><?= htmlspecialchars($can['DEPARTUREAIRPORT']) ?></div><div class="airport-name"><?= htmlspecialchars(getAirportName($can['DEPARTUREAIRPORT'])) ?></div></div>
                            <div class="flight-duration"><i class="fa-solid fa-plane"></i></div>
                            <div class="airport"><div class="airport-code"><?= htmlspecialchars($can['ARRIVALAIRPORT']) ?></div><div class="airport-name"><?= htmlspecialchars(getAirportName($can['ARRIVALAIRPORT'])) ?></div></div>
                        </div>
                        <div class="flight-details-grid">
                            <div class="detail-item"><span class="detail-label">항공사</span><span class="detail-value"><?= htmlspecialchars($can['AIRLINE']) ?></span></div>
                            <div class="detail-item"><span class="detail-label">항공편</span><span class="detail-value"><?= htmlspecialchars($can['FLIGHTNO']) ?></span></div>
                            <div class="detail-item"><span class="detail-label">출발시간</span><span class="detail-value"><?= (new DateTime($can['DEPARTUREDATETIME']))->format('Y-m-d H:i') ?></span></div>
                            <div class="detail-item"><span class="detail-label">도착시간</span><span class="detail-value"><?= (new DateTime($can['ARRIVALDATETIME']))->format('Y-m-d H:i') ?></span></div>
                            <div class="detail-item"><span class="detail-label">좌석 등급</span><span class="detail-value"><?= htmlspecialchars($can['SEATCLASS']) ?></span></div>
                            <!-- 원래 결제 금액은 취소선으로 표시하여 시각적 구분을 줍니다. -->
                            <div class="detail-item"><span class="detail-label">원래 결제 금액</span><span class="detail-value price" style="text-decoration: line-through;"><?= number_format($can['PRICE']) ?> 원</span></div>
                        </div>
                    </div>
                    <div class="card-footer">
                        <span class="booking-date">취소일: <?= (new DateTime($can['CANCELDATETIME']))->format('Y-m-d H:i') ?></span>
                        <!-- 위약금과 최종 환불액을 계산하여 표시합니다. -->
                        <div class="refund-details">
                            <div>
                                <span class="label">위약금:</span>
                                <span class="value">- <?= number_format($can['PRICE'] - $can['REFUND']) ?> 원</span>
                            </div>
                            <div class="final-refund">
                                <span class="label">환불 금액:</span>
                                <span class="value"><?= number_format($can['REFUND']) ?> 원</span>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php else: ?>
            <div class="content-panel" style="text-align:center;">조회된 취소 내역이 없습니다.</div>
        <?php endif; ?>
    </div>
</main>

<?php include 'templates/footer.php'; ?>
</body>
</html>