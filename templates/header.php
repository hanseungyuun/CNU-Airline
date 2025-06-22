<?php
/**
 * @file templates/header.php
 * @brief 모든 페이지 상단에 공통으로 포함되는 헤더 템플릿
 * @description 로고, 네비게이션 메뉴, 로그인/로그아웃/마이페이지 버튼 등을 포함합니다.
 *              각 페이지에서 선언된 $is_logged_in, $mypage_url 등의 변수를 사용하여
 *              로그인 상태에 따라 동적으로 다른 UI를 보여줍니다.
 */
?>
<!DOCTYPE html>
<html lang="ko">
<head>
    <meta charset="UTF-8">
    <!-- $page_title 변수를 사용하여 각 페이지에 맞는 제목을 동적으로 설정합니다. -->
    <title>CNU AIRLINE - <?= $page_title ?? 'Welcome' ?></title>
    <!-- Font Awesome 아이콘 및 기본 스타일시트를 로드합니다. -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css">
    <link rel="stylesheet" href="style.css">
</head>
<body>
    <header>
        <div class="header-container container">
            <div class="header-left">
                <a href="main.php" class="logo"><i class="fa-solid fa-plane-departure"></i>CNU AIR</a>
                <nav>
                    <ul>
                        <li><a href="reserve.php">예약</a></li>
                        <li><a href="#">여행 준비</a></li>
                        <li><a href="#">등급 별 혜택</a></li>
                    </ul>
                </nav>
            </div>
            <div class="header-right">
                <i class="fa-solid fa-magnifying-glass search-icon"></i>
                
                <?php // PHP를 사용하여 로그인 상태에 따라 다른 버튼을 렌더링합니다. ?>
                <?php if ($is_logged_in): ?>
                    <!-- 로그인 상태: 마이페이지와 로그아웃 버튼 표시 -->
                    <!-- $mypage_url 변수는 일반 사용자는 'mypage.php', 관리자는 'admin.php'로 동적으로 설정됩니다. -->
                    <a href="<?= htmlspecialchars($mypage_url) ?>" class="login-btn">마이페이지</a>
                    <a href="api/logout.php" class="login-btn">로그아웃</a>
                <?php else: ?>
                    <!-- 비로그인 상태: 로그인 버튼 표시 -->
                    <a href="auth.php" class="login-btn">로그인</a>
                <?php endif; ?>

            </div>
        </div>
    </header>