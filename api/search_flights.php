<?php
/**
 * @file api/search_flights.php
 * @brief 항공편을 비동기적으로 검색하는 API
 * @description reserve.php의 JavaScript(fetch) 요청을 받아,
 *              사용자가 선택한 조건(출발/도착, 날짜, 좌석, 정렬)에 맞는 항공편 목록을
 *              데이터베이스에서 조회하여 JSON 형식으로 반환합니다.
 */

// 클라이언트에게 반환될 콘텐츠 타입을 JSON으로 명시합니다.
header('Content-Type: application/json');

// DB 접속 정보가 담긴 파일을 포함합니다.
require_once 'db_connect.php';

// --- 1. 입력 파라미터 수신 및 유효성 검사 ---
// reserve.php의 fetch 요청 URL에 포함된 쿼리 파라미터를 받아 변수에 저장합니다.
$departure = $_GET['departure'] ?? null;
$arrival = $_GET['arrival'] ?? null;
$date = $_GET['date'] ?? null;
$seat_class = $_GET['seat_class'] ?? null;
$sort = $_GET['sort'] ?? 'price'; // 정렬 기준이 없으면 '요금순(price)'을 기본값으로 사용합니다.

// 필수 파라미터가 하나라도 누락된 경우, 오류 메시지를 반환하고 스크립트를 즉시 종료합니다.
if (!$departure || !$arrival || !$date || !$seat_class) {
    echo json_encode(['success' => false, 'message' => '모든 검색 조건을 입력해주세요.']);
    exit;
}

$conn = null;
try {
    // PDO를 사용하여 데이터베이스에 접속합니다.
    $conn = new PDO($dsn, $db_username, $db_password);
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // --- 2. 동적 정렬 조건 생성 ---
    // 클라이언트에서 받은 'sort' 파라미터 값에 따라 SQL의 ORDER BY 절을 동적으로 결정합니다.
    // 이를 통해 '요금순' 또는 '출발시간순' 정렬을 구현합니다.
    $orderByClause = "ORDER BY S.PRICE ASC"; // 기본 정렬: 요금 오름차순
    if ($sort === 'time') {
        $orderByClause = "ORDER BY A.DEPARTUREDATETIME ASC"; // 'time'일 경우: 출발시간 오름차순
    }

    // --- 3. 메인 SQL 쿼리 작성 ---
    // AIRPLANE 테이블과 SEATS 테이블을 조인하여 항공편 정보를 조회합니다.
    // WHERE 절에서 사용자가 선택한 출발지, 도착지, 날짜, 좌석 등급으로 결과를 필터링합니다.
    // $orderByClause 변수를 쿼리 마지막에 포함시켜 동적 정렬을 적용합니다.
    $sql = "SELECT
                A.AIRLINE,
                A.FLIGHTNO,
                TO_CHAR(A.DEPARTUREDATETIME, 'YYYY-MM-DD\"T\"HH24:MI:SS') AS DEPARTUREDATETIME,
                TO_CHAR(A.ARRIVALDATETIME, 'YYYY-MM-DD\"T\"HH24:MI:SS') AS ARRIVALDATETIME,
                A.DEPARTUREAIRPORT,
                A.ARRIVALAIRPORT,
                S.SEATCLASS,
                S.PRICE,
                -- GET_REMAINING_SEATS: 해당 항공편의 남은 좌석 수를 계산하는 저장 프로시저(Stored Procedure) 호출
                GET_REMAINING_SEATS(A.FLIGHTNO, A.DEPARTUREDATETIME, S.SEATCLASS) AS REMAINING_SEATS
            FROM AIRPLANE A
            JOIN SEATS S ON A.FLIGHTNO = S.FLIGHTNO AND A.DEPARTUREDATETIME = S.DEPARTUREDATETIME
            WHERE
                A.DEPARTUREAIRPORT = :departure
                AND A.ARRIVALAIRPORT = :arrival
                AND TRUNC(A.DEPARTUREDATETIME) = TO_DATE(:flight_date, 'YYYY-MM-DD')
                AND S.SEATCLASS = :seat_class
            $orderByClause";

    // --- 4. 쿼리 실행 및 결과 반환 ---
    // SQL Injection을 방지하기 위해 PreparedStatement를 사용합니다.
    $stmt = $conn->prepare($sql);
    // 쿼리 내 플레이스홀더(:placeholder)에 실제 값을 바인딩하여 실행합니다.
    $stmt->execute([
        ':departure' => $departure,
        ':arrival' => $arrival,
        ':flight_date' => $date,
        ':seat_class' => $seat_class
    ]);

    // 조회된 모든 결과를 연관 배열 형태로 가져옵니다.
    $flights = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // 성공 상태와 함께 조회된 항공편 목록을 JSON으로 인코딩하여 클라이언트에 응답합니다.
    echo json_encode(['success' => true, 'flights' => $flights]);

} catch (PDOException $e) {
    // 데이터베이스 작업 중 오류 발생 시, 에러 메시지를 포함한 JSON을 응답합니다.
    echo json_encode(['success' => false, 'message' => '데이터베이스 조회 오류: ' . $e->getMessage()]);
} finally {
    // 스크립트 실행이 끝나면 데이터베이스 연결을 항상 종료합니다.
    $conn = null;
}
?>