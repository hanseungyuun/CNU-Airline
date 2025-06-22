<?php
/**
 * @file admin.php
 * @brief 관리자 전용 대시보드 페이지
 * @description
 * 1. (접근 제어) 세션을 통해 현재 사용자가 관리자('c0')인지 확인하고, 아니면 다른 페이지로 리디렉션합니다.
 * 2. (데이터 조회) DB에 접속하여 '항공사별 실적'과 '고객별 랭킹' 등 주요 통계 정보를 조회합니다.
 * 3. (렌더링) 조회된 통계 데이터를 테이블 형태로 화면에 출력합니다.
 * 4. (UI) JavaScript를 사용하여 통계 탭 간의 전환 기능을 제공합니다.
 */

// --- 1. 세션 시작 및 접근 제어 ---
session_start();
require_once 'api/db_connect.php';
require_once 'api/functions.php';

$is_logged_in = isset($_SESSION['cno']);
// 세션의 'cno'가 'c0'일 경우에만 관리자로 판별합니다.
$is_admin = $is_logged_in && $_SESSION['cno'] === 'c0';
$mypage_url = $is_admin ? 'admin.php' : 'mypage.php';

// 관리자가 아닌 경우 접근을 차단하고 적절한 페이지로 리디렉션합니다.
if (!$is_admin) {
    if ($is_logged_in) {
        header('Location: mypage.php'); // 일반 사용자는 마이페이지로
    } else {
        header('Location: auth.php');   // 비로그인 사용자는 로그인 페이지로
    }
    exit;
}

// --- 2. DB에서 통계 정보 조회 ---
$conn = null;
$admin_user = null;
$airline_stats = [];
$customer_stats = [];

try {
    $conn = new PDO($dsn, $db_username, $db_password);
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // 2-1. 관리자(c0)의 기본 정보 조회
    $stmt_user = $conn->prepare("SELECT NAME, CNO, EMAIL FROM CUSTOMER WHERE CNO = 'c0'");
    $stmt_user->execute();
    $admin_user = $stmt_user->fetch(PDO::FETCH_ASSOC);

    // 2-2. 통계 쿼리 1: 항공사별 실적 (매출액 기준 내림차순 정렬)
    // AIRPLANE, SEATS, RESERVE 테이블을 조인하여 항공사별 총 예약 건수와 총 매출액을 집계합니다.
    $sql_airline = "SELECT A.AIRLINE AS AIRLINE_NAME,
                           COUNT(R.CNO) AS TOTAL_RESERVATIONS,
                           SUM(R.PAYMENT) AS TOTAL_SALES
                    FROM AIRPLANE A
                    JOIN RESERVE R ON A.FLIGHTNO = R.FLIGHTNO AND A.DEPARTUREDATETIME = R.DEPARTUREDATETIME
                    GROUP BY A.AIRLINE
                    ORDER BY TOTAL_SALES DESC";
    $stmt_airline = $conn->prepare($sql_airline);
    $stmt_airline->execute();
    $airline_stats = $stmt_airline->fetchAll(PDO::FETCH_ASSOC);

    // 2-3. 통계 쿼리 2: 우수 고객 랭킹 (누적 결제액 기준)
    // 인라인 뷰(CTOTALPAY)를 사용하여 고객별 총 결제액을 먼저 계산하고,
    // RANK() 윈도우 함수를 사용하여 순위를 매깁니다. CASE 문으로 고객 등급을 부여합니다.
    $sql_customer = "SELECT
                    C.CNO AS CUSTOMER_ID,
                    C.NAME AS CUSTOMER_NAME,
                    CTOTALPAY.TOTALPAYMENT AS TOTAL_SPENT,
                    RANK() OVER (ORDER BY CTOTALPAY.TOTALPAYMENT DESC) AS CUSTOMER_RANK,
                    CASE
                        WHEN CTOTALPAY.TOTALPAYMENT >= 1000000 THEN 'VIP'
                        WHEN CTOTALPAY.TOTALPAYMENT >= 500000 THEN 'GOLD'
                        WHEN CTOTALPAY.TOTALPAYMENT >= 200000 THEN 'SILVER'
                        ELSE 'Bronze'
                    END AS CUSTOMER_TIER
                 FROM CUSTOMER C
                 JOIN (
                    SELECT R.CNO, SUM(R.PAYMENT) AS TOTALPAYMENT
                    FROM RESERVE R
                    GROUP BY R.CNO
                 ) CTOTALPAY ON C.CNO = CTOTALPAY.CNO
                 WHERE C.CNO != 'c0' -- 관리자 계정은 랭킹에서 제외
                 ORDER BY CUSTOMER_RANK ASC";
    $stmt_customer = $conn->prepare($sql_customer);
    $stmt_customer->execute();
    $customer_stats = $stmt_customer->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    die("데이터베이스 오류: " . $e->getMessage());
} finally {
    $conn = null;
}

$page_title = '관리자 페이지';
include 'templates/header.php';
?>

<main class="content-main">
    <div class="container">
        <!-- 관리자 정보 패널 -->
        <div class="content-panel">
            <h2 class="panel-title">관리자 정보</h2>
            <ul class="info-list">
                <li class="info-item"><span class="info-label">이름</span><span class="info-value"><?= htmlspecialchars($admin_user['NAME']) ?></span></li>
                <li class="info-item"><span class="info-label">회원번호</span><span class="info-value"><?= htmlspecialchars($admin_user['CNO']) ?></span></li>
                <li class="info-item"><span class="info-label">이메일</span><span class="info-value"><?= htmlspecialchars($admin_user['EMAIL']) ?></span></li>
            </ul>
        </div>

        <!-- 통계 패널 -->
        <div class="content-panel">
            <div class="results-header">
                <h2 class="panel-title">통계 열람</h2>
                <!-- 통계 종류를 전환하는 버튼 그룹 -->
                <div class="sort-options">
                    <button id="show-airline-stats" class="active">항공사별 실적</button>
                    <button id="show-customer-stats">고객별 랭킹</button>
                </div>
            </div>

            <!-- 항공사별 실적 테이블 (기본으로 보임) -->
            <div id="airline-stats-content" class="stats-content-tab active">
                <table class="results-table">
                    <thead>
                        <tr>
                            <th>순위</th><th>항공사</th><th>총 예약 건수</th><th>총 매출액</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (count($airline_stats) > 0): ?>
                            <?php foreach ($airline_stats as $index => $stat): ?>
                                <tr>
                                    <td><?= $index + 1 ?></td>
                                    <td><?= htmlspecialchars($stat['AIRLINE_NAME']) ?></td>
                                    <td><?= number_format($stat['TOTAL_RESERVATIONS']) ?>건</td>
                                    <td><?= number_format($stat['TOTAL_SALES']) ?>원</td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr><td colspan="4">데이터가 없습니다.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <!-- 고객별 랭킹 테이블 (기본으로 숨겨짐) -->
            <div id="customer-stats-content" class="stats-content-tab">
                <table class="results-table">
                    <thead>
                        <tr>
                            <th>순위</th><th>고객 ID</th><th>이름</th><th>총 예약 금액</th><th>고객 등급</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (count($customer_stats) > 0): ?>
                            <?php foreach ($customer_stats as $stat): ?>
                                <tr>
                                    <!-- DB에서 직접 계산한 순위(CUSTOMER_RANK)를 사용 -->
                                    <td><?= htmlspecialchars($stat['CUSTOMER_RANK']) ?></td>
                                    <td><?= htmlspecialchars($stat['CUSTOMER_ID']) ?></td>
                                    <td><?= htmlspecialchars($stat['CUSTOMER_NAME']) ?></td>
                                    <td><?= number_format($stat['TOTAL_SPENT']) ?>원</td>
                                    <td><?= htmlspecialchars($stat['CUSTOMER_TIER']) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr><td colspan="5">데이터가 없습니다.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</main>

<?php include 'templates/footer.php'; ?>

<!-- 통계 탭(Tab) UI를 위한 JavaScript -->
<script>
    // --- 1. DOM 요소 참조 ---
    const showAirlineBtn = document.getElementById('show-airline-stats');
    const showCustomerBtn = document.getElementById('show-customer-stats');
    const airlineContent = document.getElementById('airline-stats-content');
    const customerContent = document.getElementById('customer-stats-content');

    // --- 2. 이벤트 리스너 등록 ---
    // '항공사별 실적' 버튼 클릭 시
    showAirlineBtn.addEventListener('click', () => {
        // 'active' 클래스를 토글하여 클릭된 버튼과 컨텐츠만 활성화합니다.
        showAirlineBtn.classList.add('active');
        showCustomerBtn.classList.remove('active');
        airlineContent.classList.add('active');
        customerContent.classList.remove('active');
    });

    // '고객별 랭킹' 버튼 클릭 시
    showCustomerBtn.addEventListener('click', () => {
        showCustomerBtn.classList.add('active');
        showAirlineBtn.classList.remove('active');
        customerContent.classList.add('active');
        airlineContent.classList.remove('active');
    });
</script>
</body>
</html>