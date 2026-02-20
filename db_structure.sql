CREATE TABLE `gridcache` (
  `id` int NOT NULL,
  `callsign` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `grid` varchar(6) COLLATE utf8mb4_unicode_ci DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;