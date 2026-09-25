import React from 'react';
import '../../subandl/subandl.css';

export default function VerificationRequired({ status, message, urls = {} }) {
    return (
        <div className="subandl">
            <section className="sb-card">
                <h1>License verification required</h1>
                <p className="sb-muted">This installation could not be verified, so access is paused. No data has been changed.</p>
                <p>Status: <span className="sb-badge sb-bad">{status}</span></p>
                {message && <p className="sb-bad">{message}</p>}
                <p>Enter or refresh your license key on the <a href={urls.license}>subscription page</a>, or contact your provider.</p>
            </section>
        </div>
    );
}
