CREATE TABLE IF NOT EXISTS `users` (
	`id` INTEGER UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
	`name` VARCHAR(500) NOT NULL,
	`edit_count` INTEGER NOT NULL,
	`created` INTEGER NOT NULL
);
CREATE TABLE IF NOT EXISTS `queries` (
	`id` INTEGER UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
	`user_id` INTEGER UNSIGNED NOT NULL,
	`wiki` VARCHAR(100) NOT NULL,
	`query` VARCHAR(500) NOT NULL,
	`created` INTEGER NOT NULL,
	`imported` TINYINT NOT NULL,
	FOREIGN KEY `queries_user_id` (`user_id`) REFERENCES `users`(`id`),
	UNIQUE KEY `queries_wiki_query` (`wiki`, `query`)
);
CREATE TABLE IF NOT EXISTS `results` (
	id INTEGER UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
	query_id INTEGER UNSIGNED NOT NULL,
	title VARCHAR(10000) NOT NULL,
	title_hash CHAR(32) NOT NULL,
	created INTEGER UNSIGNED NOT NULL,
	FOREIGN KEY `results_query_id` (`query_id`) REFERENCES `queries`(`id`),
	UNIQUE KEY `results_unique_query_title` (`query_id`, `title_hash`)
);
CREATE TABLE IF NOT EXISTS `results_sources` (
	id INTEGER UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
	results_id INTEGER UNSIGNED NOT NULL,
	user_id INTEGER UNSIGNED NOT NULL,
	source VARCHAR(32) NOT NULL,
	position TINYINT UNSIGNED NOT NULL,
	created INTEGER UNSIGNED NOT NULL,
	FOREIGN KEY `results_user_id` (`user_id`) REFERENCES `users`(`id`),
	FOREIGN KEY `results_source_results_id` (`results_id`) REFERENCES `results`(`id`),
	UNIQUE KEY `results_source_results_id_source` (`results_id`, `source`)
);
CREATE TABLE IF NOT EXISTS scores (
	id INTEGER UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
	user_id INTEGER UNSIGNED NOT NULL,
	result_id INTEGER UNSIGNED NOT NULL,
	query_id INTEGER UNSIGNED NOT NULL,
	score TINYINT UNSIGNED NOT NULL,
	created INTEGER UNSIGNED NOT NULL,
	FOREIGN KEY `scores_user_id` (user_id) REFERENCES users(id),
	FOREIGN KEY `scores_result_id` (result_id) REFERENCES results(id),
	FOREIGN KEY `scores_query_id` (query_id) REFERENCES queries(id),
	UNIQUE KEY `scores_user_result` (`user_id`, `result_id`),
	KEY `scores_user_queries` (`user_id`, `query_id`)
);
