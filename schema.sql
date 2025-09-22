CREATE TABLE IF NOT EXISTS articles (
    id SERIAL PRIMARY KEY,
    source VARCHAR(50) NOT NULL,
    title TEXT NOT NULL,
    url TEXT NOT NULL UNIQUE,
    summary TEXT,
    tags TEXT,
    quiz_question TEXT,
    quiz_options TEXT,
    quiz_correct_index INTEGER,
    image_url TEXT,
    published_at TIMESTAMPTZ,
    is_archived BOOLEAN DEFAULT false NOT NULL,
    flex_message_json TEXT,
    created_at TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP
);