<?php

namespace Tests\Unit;

use App\Models\ActivityLog;
use PHPUnit\Framework\TestCase;

class ActivityLogGrammarTest extends TestCase
{
    public function test_format_statement_parts_for_edited_manuscript(): void
    {
        $parts = ActivityLog::formatStatementParts('edited manuscript', 'Article', 'headline title');
        $this->assertEquals('edited the manuscript for the article', $parts['before_subject']);
        $this->assertEquals('headline title', $parts['subject_name']);
        $this->assertEquals('', $parts['after_subject']);
        $this->assertEquals('edited the manuscript for the article "headline title"', $parts['statement_sentence']);
    }

    public function test_format_statement_parts_for_submitted_article(): void
    {
        $parts = ActivityLog::formatStatementParts('submitted', 'Article', 'ara test');
        $this->assertEquals('submitted the article', $parts['before_subject']);
        $this->assertEquals('ara test', $parts['subject_name']);
        $this->assertEquals('', $parts['after_subject']);
        $this->assertEquals('submitted the article "ara test"', $parts['statement_sentence']);
    }

    public function test_format_statement_parts_for_returned_for_revision(): void
    {
        $parts = ActivityLog::formatStatementParts('returned for revision', 'Article', 'headline title');
        $this->assertEquals('returned the article', $parts['before_subject']);
        $this->assertEquals('headline title', $parts['subject_name']);
        $this->assertEquals('for revision', $parts['after_subject']);
        $this->assertEquals('returned the article "headline title" for revision', $parts['statement_sentence']);
    }

    public function test_format_statement_parts_for_officer_account_actions(): void
    {
        $parts = ActivityLog::formatStatementParts('created account for', 'Officer Account', 'Jane Doe');
        $this->assertEquals('created an officer account for', $parts['before_subject']);
        $this->assertEquals('Jane Doe', $parts['subject_name']);
        $this->assertEquals('created an officer account for "Jane Doe"', $parts['statement_sentence']);
    }

    public function test_format_statement_parts_for_editorial_cartoon(): void
    {
        $parts = ActivityLog::formatStatementParts('endorsed cartoon to EIC', 'Editorial Cartoon', 'Campus Life');
        $this->assertEquals('endorsed the editorial cartoon', $parts['before_subject']);
        $this->assertEquals('Campus Life', $parts['subject_name']);
        $this->assertEquals('to the EIC', $parts['after_subject']);
        $this->assertEquals('endorsed the editorial cartoon "Campus Life" to the EIC', $parts['statement_sentence']);
    }

    public function test_format_statement_parts_for_emergency_delete(): void
    {
        $parts = ActivityLog::formatStatementParts('emergency delete', 'Article', 'Spam Headline');
        $this->assertEquals('permanently deleted the article', $parts['before_subject']);
        $this->assertEquals('Spam Headline', $parts['subject_name']);
        $this->assertEquals('permanently deleted the article "Spam Headline"', $parts['statement_sentence']);
    }

    public function test_format_statement_parts_for_compound_actions(): void
    {
        $parts = ActivityLog::formatStatementParts('submitted article', 'Article', 'Breaking News');
        $this->assertEquals('submitted the article', $parts['before_subject']);
        $this->assertEquals('Breaking News', $parts['subject_name']);
        $this->assertEquals('submitted the article "Breaking News"', $parts['statement_sentence']);
    }
}
