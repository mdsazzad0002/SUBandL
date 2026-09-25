import React from 'react';
import { TERMS } from '../../subandl/subandl.js';
import '../../subandl/subandl.css';

export default function Terms({ urls = {} }) {
    return (
        <div className="subandl">
            <section className="sb-card">
                <h1>License terms</h1>
                <ol>{TERMS.map((term) => <li key={term}>{term}</li>)}</ol>
                <p><a href={urls.license}>Back to subscription</a></p>
            </section>
        </div>
    );
}
