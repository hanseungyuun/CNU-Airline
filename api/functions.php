<?php
/**
 * @file api/functions.php
 * @brief 프로젝트 전반에서 사용되는 공통 함수들을 정의하는 파일
 */

/**
 * @function getAirportName
 * @brief 공항 코드를 받아 매핑된 한글 이름을 반환합니다.
 *        DB에 공항 이름 컬럼이 없을 경우, 임시로 사용하기에 유용합니다.
 * @param string $code 공항 코드 (예: 'ICN')
 * @return string 매핑된 공항 이름 (예: '서울/인천'), 매핑값이 없으면 코드를 그대로 반환
 */
function getAirportName($code) {
    // 공항 코드와 이름 매핑 배열
    $names = [
        'ICN' => '서울/인천',
        'NRT' => '도쿄/나리타',
        'JFK' => '뉴욕/존 F.케네디',
        'SYD' => '시드니/킹즈퍼드 스미스'
        // 필요한 공항을 계속 추가...
    ];
    // 매핑된 이름이 있으면 반환하고, 없으면(null) 원본 코드를 반환합니다. (Null 병합 연산자)
    return $names[$code] ?? $code;
}
?>